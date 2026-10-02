<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a bot's secret token is rotated.
 */
final readonly class BotTokenRotated
{
    use Dispatchable;

    public function __construct(
        public readonly string $botId,
        public readonly ?string $actorId = null,
        public readonly ?string $actorType = null,
    ) {
    }
}
