<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Commands;

use BAGArt\TelegramBot\Contracts\Outbound\AtomicDlqQueue;
use BAGArt\TelegramBot\Contracts\Outbound\ChannelDiscoverableQueue;
use BAGArt\TelegramBot\Contracts\Outbound\OutboundQueueContract;
use BAGArt\TelegramBot\Outbound\Config\OutboundSetup;
use BAGArt\TelegramBot\Outbound\TgOutboundStats;
use Illuminate\Console\Command;

class TgOutboundToolCommand extends Command
{
    protected $signature = 'tg:outbound:tool
                            {--status : Show queue sizes (ready/delayed/inflight/dlq)}
                            {--workers : Show live workers (heartbeat)}
                            {--locks : Show active ordering locks}
                            {--unlock-chat= : Release a specific ordering lock}
                            {--bot-id= : Bot ID for unlock-chat/trace-task}
                            {--chat-id= : Chat ID for unlock-chat}
                            {--trace-task= : Search task by ID}
                            {--peek : Peek at queue tasks}
                            {--limit=50 : Max results}
                            {--delayed : Show delayed tasks in retry}
                            {--bottlenecks : Show top retry reasons}
                            {--json : Structured JSON output}';

    protected $description = 'Inspect and manage the outbound queue';

    public function handle(
        OutboundSetup $outboundSetup,
    ): int {
        $json = (bool)$this->option('json');
        $queue = $outboundSetup->queue;
        $stats = $outboundSetup->stats;
        $limit = (int)$this->option('limit');

        if ($this->option('status')) {
            return $this->showStatus($queue, $stats, $json);
        }

        if ($this->option('workers')) {
            return $this->showWorkers($json);
        }

        if ($this->option('locks')) {
            return $this->showLocks($json);
        }

        if ($this->option('unlock-chat')) {
            $botId = (string)$this->option('bot-id');
            $chatId = $this->option('chat-id') ?: (string)$this->option('unlock-chat');

            return $this->unlockChat($botId, $chatId, $json);
        }

        if ($this->option('trace-task')) {
            return $this->traceTask((string)$this->option('trace-task'), $json);
        }

        if ($this->option('peek')) {
            return $this->peek($limit, $json);
        }

        if ($this->option('delayed')) {
            return $this->showDelayed($limit, $json);
        }

        if ($this->option('bottlenecks')) {
            return $this->showBottlenecks($stats, $limit, $json);
        }

        $this->error('No action specified. Use --help to see available options.');

        return self::FAILURE;
    }

    private function showStatus(OutboundQueueContract $queue, TgOutboundStats $stats, bool $json): int
    {
        $size = $queue->size();

        $dlqSize = 0;
        $channels = 0;
        if ($queue instanceof ChannelDiscoverableQueue) {
            $dlqChannels = $queue->getDlqChannels('tg-dlq:*');
            $channels = count($dlqChannels);
            if ($queue instanceof AtomicDlqQueue) {
                foreach ($dlqChannels as $ch) {
                    $dlqSize += $queue->deadLetterSize($ch);
                }
            }
        }

        $state = [
            'queue_size' => $size,
            'dlq_size' => $dlqSize,
            'dlq_channels' => $channels,
            'stats_hours' => $stats->getState(),
        ];

        if ($json) {
            $this->line(json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->table(['Metric', 'Value'], [
            ['Queue size', $size],
            ['DLQ entries', $dlqSize],
            ['DLQ channels', $channels],
        ]);

        return self::SUCCESS;
    }

    private function showWorkers(bool $json): int
    {
        $this->warn('Worker heartbeat inspection requires direct Redis access. Use standalone outbound-tool.php with --mode=multi.');

        return self::SUCCESS;
    }

    private function showLocks(bool $json): int
    {
        $this->warn('Lock inspection requires direct Redis access. Use standalone outbound-tool.php with --mode=multi.');

        return self::SUCCESS;
    }

    private function unlockChat(string $botId, string $chatId, bool $json): int
    {
        $this->warn('Unlock requires direct Redis access. Use standalone outbound-tool.php with --mode=multi --bot-id=... --chat-id=...');

        return self::SUCCESS;
    }

    private function traceTask(string $taskId, bool $json): int
    {
        $this->warn('Task tracing requires direct Redis access. Use standalone outbound-tool.php with --mode=multi --trace-task=...');

        return self::SUCCESS;
    }

    private function peek(int $limit, bool $json): int
    {
        $this->warn('Peek requires direct Redis access. Use standalone outbound-tool.php with --mode=multi --peek --limit='.$limit);

        return self::SUCCESS;
    }

    private function showDelayed(int $limit, bool $json): int
    {
        $this->warn('Delayed task inspection requires direct Redis access. Use standalone outbound-tool.php with --mode=multi --delayed');

        return self::SUCCESS;
    }

    private function showBottlenecks(TgOutboundStats $stats, int $limit, bool $json): int
    {
        $fromHour = date('YmdH', time() - 3600);
        $toHour = date('YmdH');
        $metrics = $stats->getGlobalMetrics($fromHour, $toHour);

        if ($json) {
            $this->line(json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($metrics as $key => $value) {
            $rows[] = [$key, $value];
        }

        $this->table(['Metric', 'Count'], $rows);

        return self::SUCCESS;
    }
}
