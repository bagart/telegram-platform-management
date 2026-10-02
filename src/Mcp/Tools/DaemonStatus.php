<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Mcp\Tools;

use BAGArt\TelegramBot\Contracts\Outbound\OutboundQueueContract;
use BAGArt\TelegramBot\Outbound\TgOutboundStats;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Indirect daemon-liveness signal.
 *
 * IMPORTANT: there is no PID file or heartbeat write in {@see \BAGArt\TelegramBot\Outbound\TgOutboundDaemon},
 * so this tool CANNOT authoritatively answer "is the daemon process up?". It infers
 * liveness from two indirect signals: queue depth (growing = likely stalled) and
 * whether the last hour recorded any sent metric (sent_global > 0 = recently active).
 * For an authoritative signal, a heartbeat write in TgOutboundDaemon::tick() would be
 * required — see docs/INDEX.md "Missing/future".
 */
#[IsReadOnly]
class DaemonStatus extends Tool
{
    protected string $description = 'Indirect daemon-liveness signal. There is NO PID file or heartbeat, so this cannot authoritatively report process status. It infers activity from queue depth and the last hour\'s sent metric: likely_up (sent>0), likely_down (sent==0 && queue growing), or idle (sent==0 && queue empty). Treat likely_down as a prompt to check the daemon process manually.';

    public function __construct(
        private readonly OutboundQueueContract $queue,
        private readonly TgOutboundStats $stats,
    ) {
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $size = $this->queue->size();

        $lastHour = date('YmdH');
        $metrics = $this->stats->getGlobalMetrics($lastHour, $lastHour);
        $sent = (int)($metrics["{$lastHour}:sent"] ?? 0);

        $assessment = match (true) {
            $sent > 0 => 'likely_up',
            $size > 0 => 'likely_down',
            default => 'idle',
        };

        return Response::json([
            'assessment' => $assessment,
            'queue_size' => $size,
            'last_hour_sent' => $sent,
            'last_hour' => $lastHour,
            'caveat' => 'Indirect signal only. No PID file or heartbeat exists. Verify the daemon process manually if assessment is likely_down.',
        ]);
    }
}
