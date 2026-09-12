<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a bot is deleted.
 */
final class BotDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $botId,
    ) {}
}
