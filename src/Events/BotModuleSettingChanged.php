<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a module enablement is changed via TgModuleEnablementService.
 */
final class BotModuleSettingChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $moduleId,
        public readonly ?string $botId,
        public readonly ?int $chatId,
        public readonly ?bool $isEnabled,
        public readonly ?array $settings,
    ) {}
}
