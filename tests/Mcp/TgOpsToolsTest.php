<?php

declare(strict_types=1);

/**
 * Feature tests for the tg-ops MCP tools.
 *
 * Each tool is exercised through its handle() method directly — the MCP transport
 * (stdio framing) is already covered by the manual smoke test in the skill. Here
 * we assert the tool's contract: happy-path returns the right payload, and
 * capability-guard paths return Response::error() when the broker lacks support.
 *
 * The in-memory queue (InMemoryOutboundQueue) implements every capability
 * interface, so it is used for happy paths. A bare stub missing the capability
 * interfaces is used for the guard-error paths.
 */

use BAGArt\TelegramBot\Contracts\Outbound\OutboundQueueContract;
use BAGArt\TelegramBot\Outbound\Adapters\InMemoryOutboundQueue;
use BAGArt\TelegramBot\Outbound\Config\OutboundWorkerConfig;
use BAGArt\TelegramBot\Outbound\OutboundEnvelope;
use BAGArt\TelegramBot\Outbound\OutboundTask;
use BAGArt\TelegramBot\Outbound\TgOutboundStats;
use BAGArt\TelegramBotManagement\Mcp\Tools\BotList;
use BAGArt\TelegramBotManagement\Mcp\Tools\DaemonStatus;
use BAGArt\TelegramBotManagement\Mcp\Tools\DlqList;
use BAGArt\TelegramBotManagement\Mcp\Tools\QueueDepth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * In-memory OutboundCacheContract that fakes incrementWithTtl + get/put.
 * Matches the shape TgOutboundStats needs.
 */
function makeInMemoryStatsCache(): BAGArt\TelegramBot\Contracts\Outbound\OutboundCacheContract
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

describe('QueueDepth', function () {
    it('returns the global queue size', function () {
        $queue = new InMemoryOutboundQueue(new BAGArt\AsyncKernel\ASKClock());
        $tool = new QueueDepth($queue);

        $response = $tool->handle(new Request([]));

        expect($response->isError())->toBeFalse();
        $payload = json_decode((string) $response->content(), true);
        expect($payload['size'])->toBe(0)
            ->and($payload['channel'])->toBe('tg-outbound');
    });
});

describe('OutboundMetrics', function () {
    it('returns a 24h state snapshot when no range is given', function () {
        $stats = new TgOutboundStats(makeInMemoryStatsCache());
        $tool = new BAGArt\TelegramBotManagement\Mcp\Tools\OutboundMetrics($stats);

        $response = $tool->handle(new Request([]));

        expect($response->isError())->toBeFalse();
        $payload = json_decode((string) $response->content(), true);
        expect($payload['mode'])->toBe('state_24h')
            ->and($payload)->toHaveKey('state');
    });

    it('rejects malformed hour format', function () {
        $stats = new TgOutboundStats(makeInMemoryStatsCache());
        $tool = new BAGArt\TelegramBotManagement\Mcp\Tools\OutboundMetrics($stats);

        $response = $tool->handle(new Request(['from_hour' => 'not-a-hour']));

        expect($response->isError())->toBeTrue()
            ->and((string) $response->content())->toContain('YmdH');
    });
});

describe('DlqList', function () {
    it('returns an empty list when the DLQ is empty', function () {
        $queue = new InMemoryOutboundQueue(new BAGArt\AsyncKernel\ASKClock());
        $tool = new DlqList($queue, new OutboundWorkerConfig());

        $response = $tool->handle(new Request([]));

        expect($response->isError())->toBeFalse();
        $payload = json_decode((string) $response->content(), true);
        expect($payload['entries'])->toBe([])
            ->and($payload['count'])->toBe(0);
    });

    it('returns a capability error when the broker lacks AtomicDlqQueueContract', function () {
        // A bare stub that implements only the base contract — no DLQ capability.
        $bareQueue = new class () implements OutboundQueueContract {
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
        $tool = new DlqList($bareQueue, new OutboundWorkerConfig());

        $response = $tool->handle(new Request([]));

        expect($response->isError())->toBeTrue()
            ->and((string) $response->content())->toContain('AtomicDlqQueueContract');
    });
});

describe('DaemonStatus', function () {
    it('reports idle when queue is empty and no recent sends', function () {
        $queue = new InMemoryOutboundQueue(new BAGArt\AsyncKernel\ASKClock());
        $stats = new TgOutboundStats(makeInMemoryStatsCache());
        $tool = new DaemonStatus($queue, $stats);

        $response = $tool->handle(new Request([]));

        expect($response->isError())->toBeFalse();
        $payload = json_decode((string) $response->content(), true);
        expect($payload['assessment'])->toBe('idle')
            ->and($payload)->toHaveKey('caveat')
            ->and($payload['caveat'])->toContain('Indirect');
    });

    it('reports likely_down when queue has items but no sent metrics', function () {
        $queue = new InMemoryOutboundQueue(new BAGArt\AsyncKernel\ASKClock());
        $clock = new BAGArt\AsyncKernel\ASKClock();
        $task = new OutboundTask(
            id: bin2hex(random_bytes(16)),
            botConfig: new \BAGArt\TelegramBot\Configs\TgBotConfig(token: '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdef', botId: '123456'),
            dtoClass: \BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO::class,
            dtoData: ['chat_id' => 1, 'text' => 'test'],
        );
        $queue->push($task);

        $stats = new TgOutboundStats(makeInMemoryStatsCache());
        $tool = new DaemonStatus($queue, $stats);

        $response = $tool->handle(new Request([]));

        expect($response->isError())->toBeFalse();
        $payload = json_decode((string) $response->content(), true);
        expect($payload['assessment'])->toBe('likely_down')
            ->and($payload['queue_size'])->toBeGreaterThan(0)
            ->and($payload['last_hour_sent'])->toBe(0);
    });

    it('reports likely_up when sent metrics are non-zero', function () {
        $cache = makeInMemoryStatsCache();
        $queue = new InMemoryOutboundQueue(new BAGArt\AsyncKernel\ASKClock());
        $stats = new TgOutboundStats($cache);

        $hour = date('YmdH');
        $cache->put("tg_outbound:stats:{$hour}:sent:global", 5, 3600);

        $tool = new DaemonStatus($queue, $stats);

        $response = $tool->handle(new Request([]));

        expect($response->isError())->toBeFalse();
        $payload = json_decode((string) $response->content(), true);
        expect($payload['assessment'])->toBe('likely_up')
            ->and($payload['last_hour_sent'])->toBe(5)
            ->and($payload['queue_size'])->toBe(0);
    });
});

describe('BotList', function () {
    it('returns a bots array (may be empty in test DB)', function () {
        $tool = new BotList();
        $response = $tool->handle(new Request([]));

        expect($response->isError())->toBeFalse();
        $payload = json_decode((string) $response->content(), true);
        expect($payload)->toHaveKey('bots')
            ->and($payload)->toHaveKey('count')
            ->and(is_array($payload['bots']))->toBeTrue();
    });
});
