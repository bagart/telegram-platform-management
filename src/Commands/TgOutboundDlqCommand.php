<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Commands;

use BAGArt\TelegramBot\Contracts\Outbound\AtomicDlqQueue;
use BAGArt\TelegramBot\Contracts\Outbound\ChannelDiscoverableQueue;
use BAGArt\TelegramBot\Contracts\Outbound\PurgeableQueue;
use BAGArt\TelegramBot\Outbound\Config\OutboundSetup;
use BAGArt\TelegramBot\Outbound\DeadLetterEntry;
use BAGArt\TelegramBot\Outbound\TgOutboundStats;
use Illuminate\Console\Command;

class TgOutboundDlqCommand extends Command
{
    protected $signature = 'tg:outbound:dlq
                            {--list : List DLQ entries}
                            {--bot= : Filter by bot ID}
                            {--retry= : Retry a specific DLQ entry by ID}
                            {--retry-all : Retry all DLQ entries}
                            {--purge : Purge expired DLQ entries}
                            {--before=30 : Days before which to purge (default: 30)}
                            {--limit=50 : Max DLQ entries to show (default: 50)}
                            {--json : Structured JSON output}';

    protected $description = 'Manage the Dead Letter Queue';

    public function handle(
        OutboundSetup $outboundSetup,
    ): int {
        $queue = $outboundSetup->queue;
        $stats = $outboundSetup->stats;
        $json = (bool)$this->option('json');
        $limit = (int)$this->option('limit');

        if (!$queue instanceof AtomicDlqQueue) {
            $this->error('The current queue adapter does not support DLQ operations.');

            return self::FAILURE;
        }

        if ($this->option('list')) {
            return $this->listDlq($queue, $stats, $json, $limit);
        }

        if ($this->option('retry')) {
            return $this->retryEntry($queue, $stats, $json);
        }

        if ($this->option('retry-all')) {
            return $this->retryAll($queue, $stats, $json);
        }

        if ($this->option('purge')) {
            return $this->purgeDlq($queue, $stats, $json);
        }

        $this->error('No action specified. Use --help to see available options.');

        return self::FAILURE;
    }

    private function listDlq(
        AtomicDlqQueue&ChannelDiscoverableQueue $queue,
        TgOutboundStats $stats,
        bool $json,
        int $limit,
    ): int {
        $botId = $this->option('bot');
        $queue instanceof ChannelDiscoverableQueue;

        $channels = $queue->getDlqChannels('tg-dlq:*');
        if ($botId !== null) {
            $channels = array_values(array_filter(
                $channels,
                fn (string $ch) => str_ends_with($ch, $botId),
            ));

            if ($channels === []) {
                $this->warn("No DLQ channel found for bot: {$botId}");

                return self::SUCCESS;
            }
        }

        $entries = $queue->listDeadLetter($channels !== [] ? $channels[0] : null, $limit);

        if ($json) {
            $data = array_map(fn (DeadLetterEntry $e) => [
                'id' => $e->id,
                'reason' => $e->reason,
                'failed_at' => $e->failedAt,
                'task_class' => $e->originalTask['dtoClass'] ?? 'unknown',
                'redelivery_count' => $e->redeliveryCount,
                'can_redeliver' => $e->canRedeliver(),
            ], $entries);

            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("DLQ Entries (" . count($entries) . ")");
        $this->newLine();

        $rows = array_map(fn (DeadLetterEntry $e) => [
            $e->id,
            $e->reason,
            $e->failedAt,
            $e->originalTask['dtoClass'] ?? 'unknown',
            $e->redeliveryCount,
            $e->canRedeliver() ? 'yes' : 'no',
        ], $entries);

        $this->table(['ID', 'Reason', 'Failed At', 'Task', 'Retries', 'Can Retry'], $rows);

        return self::SUCCESS;
    }

    private function retryEntry(
        AtomicDlqQueue&ChannelDiscoverableQueue $queue,
        TgOutboundStats $stats,
        bool $json,
    ): int {
        $entryId = (string)$this->option('retry');
        $queue instanceof ChannelDiscoverableQueue;

        $channels = $queue->getDlqChannels('tg-dlq:*');
        $found = false;

        foreach ($channels as $channel) {
            $raw = $queue->atomicFetchAndRemoveFromDlq($channel, $entryId);
            if ($raw === null) {
                continue;
            }

            $entryData = json_decode($raw, true);
            if (!is_array($entryData)) {
                continue;
            }

            $entry = DeadLetterEntry::fromJson($entryData);

            if (!$entry->canRedeliver()) {
                $this->error("Entry {$entryId} has exceeded max redeliveries (" . DeadLetterEntry::MAX_REDELIVERIES . ").");

                return self::FAILURE;
            }

            $envelope = $entry->restoreEnvelope();
            $queue->push($envelope->task);
            $stats->recordDlqRetried($envelope->task->botId);

            $found = true;

            if ($json) {
                $this->line(json_encode([
                    'retried' => $entryId,
                    'bot_id' => $envelope->task->botId,
                    'dto_class' => $envelope->task->dtoClass,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->info("Retried DLQ entry {$entryId} (bot: {$envelope->task->botId}, class: {$envelope->task->dtoClass})");
            }

            break;
        }

        if (!$found) {
            $this->error("DLQ entry {$entryId} not found.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function retryAll(
        AtomicDlqQueue&ChannelDiscoverableQueue $queue,
        TgOutboundStats $stats,
        bool $json,
    ): int {
        $queue instanceof ChannelDiscoverableQueue;

        $botId = $this->option('bot');
        $channels = $queue->getDlqChannels('tg-dlq:*');

        if ($botId !== null) {
            $channels = array_values(array_filter(
                $channels,
                fn (string $ch) => str_ends_with($ch, $botId),
            ));
        }

        $total = 0;
        $errors = 0;

        foreach ($channels as $channel) {
            $entries = $queue->listDeadLetter($channel, 1000);
            foreach ($entries as $entry) {
                if (!$entry->canRedeliver()) {
                    continue;
                }

                $raw = $queue->atomicFetchAndRemoveFromDlq($channel, $entry->id);
                if ($raw === null) {
                    continue;
                }

                try {
                    $envelope = $entry->restoreEnvelope();
                    $queue->push($envelope->task);
                    $stats->recordDlqRetried($envelope->task->botId);
                    $total++;
                } catch (\Throwable) {
                    $errors++;
                }
            }
        }

        if ($json) {
            $this->line(json_encode([
                'retried' => $total,
                'errors' => $errors,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info("Retried {$total} DLQ entries" . ($errors > 0 ? " ({$errors} errors)" : ""));
        }

        return self::SUCCESS;
    }

    private function purgeDlq(
        AtomicDlqQueue&ChannelDiscoverableQueue&PurgeableQueue $queue,
        TgOutboundStats $stats,
        bool $json,
    ): int {
        $days = (int)$this->option('before');
        $beforeTimestamp = time() - ($days * 86400);
        $queue instanceof PurgeableQueue;

        $purged = $queue->purgeExpired('tg-dlq:*', $beforeTimestamp);
        $stats->recordDlqPurged($purged);

        if ($json) {
            $this->line(json_encode([
                'purged' => $purged,
                'before_days' => $days,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info("Purged {$purged} DLQ entries older than {$days} days.");
        }

        return self::SUCCESS;
    }
}
