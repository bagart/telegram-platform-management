<?php

declare(strict_types=1);

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Outbound\OutboundQueueContract;
use BAGArt\TelegramBot\Outbound\Adapters\InMemoryOutboundQueue;
use BAGArt\TelegramBot\Outbound\Config\OutboundWorkerConfig;
use BAGArt\TelegramBot\Outbound\OutboundEnvelope;
use BAGArt\TelegramBot\Outbound\OutboundTask;
use BAGArt\TelegramBot\Outbound\OutboundTaskState;
use BAGArt\TelegramBot\Outbound\TgOutboundStats;
use BAGArt\TelegramBotManagement\Mcp\Tools\DlqPurge;
use BAGArt\TelegramBotManagement\Mcp\Tools\DlqRetry;
use BAGArt\AsyncKernel\ASKClock;
use Laravel\Mcp\Request;

function makeMcpStatsCache(): BAGArt\TelegramBot\Contracts\Outbound\OutboundCacheContract
{
    return new class () implements BAGArt\TelegramBot\Contracts\Outbound\OutboundCacheContract {
        private array $store = [];

        public function incrementWithTtl(string $key, int $value, int $ttlSec): int
        {
            $this->store[$key] = ($this->store[$key] ?? 0) + $value;

            return $this->store[$key];
        }

        public function lock(string $key, int $ttlSec, ?string $owner = null): bool
        {
            return true;
        }

        public function unlock(string $key, ?string $owner = null): void
        {
        }

        public function get(string $key): mixed
        {
            return $this->store[$key] ?? null;
        }

        public function put(string $key, mixed $value, int $ttlSec): void
        {
            $this->store[$key] = $value;
        }

        public function forget(string $key): void
        {
            unset($this->store[$key]);
        }
    };
}

function makeBareQueueForGuard(): OutboundQueueContract
{
    return new class () implements OutboundQueueContract {
        public function push(OutboundTask $task): void
        {
        }

        public function pop(int $visibilityTimeoutSec = 60): ?OutboundEnvelope
        {
            return null;
        }

        public function ack(OutboundEnvelope $envelope): void
        {
        }

        public function release(OutboundEnvelope $envelope, int $delaySec): void
        {
        }

        public function size(): int
        {
            return 0;
        }
    };
}

describe('DlqRetry MCP tool', function () {
    it('returns capability error when queue lacks AtomicDlqQueueContract', function () {
        $tool = new DlqRetry(makeBareQueueForGuard(), new TgOutboundStats(makeMcpStatsCache()), new OutboundWorkerConfig());

        $response = $tool->handle(new Request(['entry_id' => 'test-id']));

        expect($response->isError())->toBeTrue()
            ->and((string) $response->content())->toContain('AtomicDlqQueueContract');
    });

    it('returns error for empty entry_id', function () {
        $queue = new InMemoryOutboundQueue(new ASKClock());
        $tool = new DlqRetry($queue, new TgOutboundStats(makeMcpStatsCache()), new OutboundWorkerConfig());

        $response = $tool->handle(new Request(['entry_id' => '']));

        expect($response->isError())->toBeTrue()
            ->and((string) $response->content())->toContain('entry_id is required');
    });

    it('returns error when entry is not found', function () {
        $queue = new InMemoryOutboundQueue(new ASKClock());
        $tool = new DlqRetry($queue, new TgOutboundStats(makeMcpStatsCache()), new OutboundWorkerConfig());

        $response = $tool->handle(new Request(['entry_id' => 'nonexistent']));

        expect($response->isError())->toBeTrue()
            ->and((string) $response->content())->toContain('not found');
    });

    it('retries a DLQ entry and moves it back to main queue', function () {
        $queue = new InMemoryOutboundQueue(new ASKClock());
        $stats = new TgOutboundStats(makeMcpStatsCache());
        $tool = new DlqRetry($queue, $stats, new OutboundWorkerConfig());

        $task = new OutboundTask(
            id: 'dlq-retry-test',
            botConfig: new TgBotConfig(token: 'test:token', botId: 'bot1'),
            dtoClass: 'SendMsg',
            dtoData: ['chat_id' => 1],
        );
        $envelope = new OutboundEnvelope($task, new OutboundTaskState());
        $queue->pushToDeadLetter($envelope, 'test_error');

        expect($queue->deadLetterSize())->toBe(1)
            ->and($queue->size())->toBe(0);

        $response = $tool->handle(new Request(['entry_id' => 'dlq-retry-test']));

        expect($response->isError())->toBeFalse()
            ->and($queue->deadLetterSize())->toBe(0)
            ->and($queue->size())->toBe(1);

        $payload = json_decode((string) $response->content(), true);
        expect($payload['retried'])->toBe('dlq-retry-test')
            ->and($payload['bot_id'])->toBe('bot1');
    });
});

describe('DlqPurge MCP tool', function () {
    it('returns capability error when queue lacks AtomicDlqQueueContract', function () {
        $tool = new DlqPurge(makeBareQueueForGuard(), new TgOutboundStats(makeMcpStatsCache()));

        $response = $tool->handle(new Request(['older_than_days' => 30]));

        expect($response->isError())->toBeTrue()
            ->and((string) $response->content())->toContain('AtomicDlqQueueContract');
    });

    it('returns error for negative days', function () {
        $queue = new InMemoryOutboundQueue(new ASKClock());
        $tool = new DlqPurge($queue, new TgOutboundStats(makeMcpStatsCache()));

        $response = $tool->handle(new Request(['older_than_days' => -1]));

        expect($response->isError())->toBeTrue()
            ->and((string) $response->content())->toContain('non-negative');
    });

    it('purges with zero days removes nothing from fresh queue', function () {
        $queue = new InMemoryOutboundQueue(new ASKClock());
        $tool = new DlqPurge($queue, new TgOutboundStats(makeMcpStatsCache()));

        $response = $tool->handle(new Request(['older_than_days' => 0]));

        expect($response->isError())->toBeFalse();
        $payload = json_decode((string) $response->content(), true);
        expect($payload['purged'])->toBe(0)
            ->and($payload['older_than_days'])->toBe(0);
    });

    it('purges entries older than specified days', function () {
        $queue = new InMemoryOutboundQueue(new ASKClock());
        $stats = new TgOutboundStats(makeMcpStatsCache());
        $tool = new DlqPurge($queue, $stats);

        $task = new OutboundTask(
            id: 'purge-test',
            botConfig: new TgBotConfig(token: 'test:token', botId: 'bot1'),
            dtoClass: 'SendMsg',
            dtoData: [],
        );
        $envelope = new OutboundEnvelope($task, new OutboundTaskState());
        $queue->pushToDeadLetter($envelope, 'expired');

        $response = $tool->handle(new Request(['older_than_days' => 30]));

        expect($response->isError())->toBeFalse();
        $payload = json_decode((string) $response->content(), true);
        expect($payload)->toHaveKey('purged')
            ->and($payload)->toHaveKey('older_than_days');
    });
});
