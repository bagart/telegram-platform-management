<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a new bot is registered via TelegramBotManager::addBot().
 */
final class BotCreated
{
    use Dispatchable;

    public function __construct(
        public readonly string $botId,
        public readonly string $secretToken,
    ) {}
}
