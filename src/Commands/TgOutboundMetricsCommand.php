<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Commands;

use BAGArt\TelegramBot\Contracts\Outbound\AtomicDlqQueue;
use BAGArt\TelegramBot\Contracts\Outbound\ChannelDiscoverableQueue;
use BAGArt\TelegramBot\Contracts\Outbound\OutboundQueueContract;
use BAGArt\TelegramBot\Outbound\Config\OutboundSetup;
use BAGArt\TelegramBot\Outbound\TgOutboundStats;
use Illuminate\Console\Command;

class TgOutboundMetricsCommand extends Command
{
    protected $signature = 'tg:outbound:metrics
                            {--from= : Start hour in YYYYMMDDHH format (default: 1 hour ago)}
                            {--to= : End hour in YYYYMMDDHH format (default: now)}
                            {--bot-id= : Filter by bot ID}
                            {--json : Structured JSON output}
                            {--watch : Continuously watch metrics every N seconds}
                            {--interval=5 : Refresh interval in seconds (default: 5)}';

    protected $description = 'View outbound queue metrics';

    public function handle(
        OutboundSetup $outboundSetup,
    ): int {
        $stats = $outboundSetup->stats;
        $queue = $outboundSetup->queue;
        $json = (bool)$this->option('json');
        $botId = $this->option('bot-id');

        $fromHour = $this->option('from') ?: date('YmdH', time() - 3600);
        $toHour = $this->option('to') ?: date('YmdH');

        if ($this->option('watch')) {
            return $this->watchMetrics($stats, $queue, $fromHour, $toHour, $botId, $json);
        }

        return $this->showMetrics($stats, $queue, $fromHour, $toHour, $botId, $json);
    }

    private function showMetrics(
        TgOutboundStats $stats,
        OutboundQueueContract $queue,
        string $fromHour,
        string $toHour,
        ?string $botId,
        bool $json,
    ): int {
        $metrics = $botId !== null
            ? $stats->getBotMetrics($botId, $fromHour, $toHour)
            : $stats->getGlobalMetrics($fromHour, $toHour);

        $dlqSize = 0;
        if ($queue instanceof ChannelDiscoverableQueue && $queue instanceof AtomicDlqQueue) {
            $channels = $queue->getDlqChannels('tg-dlq:*');
            foreach ($channels as $ch) {
                $dlqSize += $queue->deadLetterSize($ch);
            }
        }

        $stateData = $stats->getState();

        if ($json) {
            $this->line(json_encode([
                'period' => ['from' => $fromHour, 'to' => $toHour],
                'bot_id' => $botId,
                'queue_size' => $queue->size(),
                'dlq_size' => $dlqSize,
                'metrics' => $metrics,
                'hourly' => $stateData,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Outbound Metrics ({$fromHour} - {$toHour})");
        if ($botId !== null) {
            $this->line("Bot: {$botId}");
        }
        $this->newLine();

        $this->table(['Metric', 'Count'], [
            ['Queue size', $queue->size()],
            ['DLQ entries', $dlqSize],
        ]);

        $this->newLine();
        $this->table(
            ['Hour', 'Sent', 'Retry (rate_limit)', 'Retry (circuit)', 'Failed', 'Business Err', 'DLQ Pushed'],
            array_map(fn (string $key, int $value) => [
                explode(':', $key)[0] ?? $key,
                $metrics[explode(':', $key)[0].':sent'] ?? 0,
                $metrics[explode(':', $key)[0].':retry:rate_limit'] ?? 0,
                $metrics[explode(':', $key)[0].':retry:circuit_breaker'] ?? 0,
                $metrics[explode(':', $key)[0].':failed:network'] ?? 0,
                $metrics[explode(':', $key)[0].':business_error'] ?? 0,
                $metrics[explode(':', $key)[0].':dlq_pushed'] ?? 0,
            ], array_keys($metrics), $metrics)
        );

        return self::SUCCESS;
    }

    private function watchMetrics(
        TgOutboundStats $stats,
        OutboundQueueContract $queue,
        string $fromHour,
        string $toHour,
        ?string $botId,
        bool $json,
    ): int {
        $interval = max(1, (int)$this->option('interval'));

        while (true) {
            if (!$json) {
                echo "\033[2J\033[H";
                $this->info("Outbound Metrics Watch (interval: {$interval}s)");
                $this->line('Time: '.date('Y-m-d H:i:s'));
                $this->newLine();
            }

            $this->showMetrics($stats, $queue, $fromHour, $toHour, $botId, $json);
            sleep($interval);
        }
    }
}
