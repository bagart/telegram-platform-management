<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Commands;

use BAGArt\TelegramBot\Modules\TgModuleRegistry;
use BAGArt\TelegramBotManagement\Services\TgModuleEnablementService;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationService;
use Illuminate\Console\Command;

/**
 * CLI toggles ride the guarded writer API (single-writer rule, §8.6).
 *
 * When enablement_driver='engine', writes go through ModuleActivationService
 * (bot_module_activations, bot-level only). When 'legacy', writes go through
 * TgModuleEnablementService (tg_module_enablements, 3-tier: chat/bot/platform).
 */
abstract class TgModuleToggleCommand extends Command
{
    protected bool $enable;

    public function handle(
        TgModuleRegistry $registry,
        ?TgModuleEnablementService $legacyEnablement = null,
        ?ModuleActivationService $engineActivation = null,
    ): int {
        $moduleId = (string)$this->argument('module');
        $botId = $this->option('bot');
        $chatId = $this->option('chat') !== null ? (int)$this->option('chat') : null;

        if (!is_string($botId) || $botId === '') {
            $botId = null;
        }

        if (!$registry->has($moduleId)) {
            $this->error("Module '{$moduleId}' is not discovered. See tg:modules:list.");

            return self::FAILURE;
        }

        $driver = config('tg_modules.enablement_driver', 'legacy');

        if ($driver === 'engine') {
            return $this->handleEngine($moduleId, $botId, $chatId, $engineActivation);
        }

        return $this->handleLegacy($moduleId, $botId, $chatId, $legacyEnablement);
    }

    private function handleEngine(
        string $moduleId,
        ?string $botId,
        ?int $chatId,
        ?ModuleActivationService $activations,
    ): int {
        if ($activations === null) {
            $this->error('ModuleActivationService is not bound. Is the engine provider registered?');

            return self::FAILURE;
        }

        if ($chatId !== null) {
            $this->error('Chat-level activation is not supported with engine driver. Use --bot only.');

            return self::FAILURE;
        }

        if ($botId === null) {
            $this->error('--bot is required with engine driver (bot-level activation only).');

            return self::FAILURE;
        }

        $result = $this->enable
            ? $activations->enable($botId, $moduleId)
            : $activations->disable($botId, $moduleId);

        $outcome = $result->outcome->name;
        $this->info("{$outcome}: module '{$moduleId}' for bot '{$botId}' (revision {$result->revision}).");

        return $result->applied() ? self::SUCCESS : self::FAILURE;
    }

    private function handleLegacy(
        string $moduleId,
        ?string $botId,
        ?int $chatId,
        ?TgModuleEnablementService $enablement,
    ): int {
        if ($enablement === null) {
            $this->error('TgModuleEnablementService is not bound.');

            return self::FAILURE;
        }

        if ($botId === null && $chatId !== null) {
            $this->error('--chat requires --bot.');

            return self::FAILURE;
        }

        $enablement->setEnabled($moduleId, $botId, $chatId, $this->enable);

        $scope = $this->describeScope($botId, $chatId);
        $this->info(($this->enable ? 'Enabled' : 'Disabled')." module '{$moduleId}' {$scope}.");

        return self::SUCCESS;
    }

    private function describeScope(?string $botId, ?int $chatId): string
    {
        if ($botId === null) {
            return 'platform-wide (bot_id NULL)';
        }

        return $chatId !== null
            ? "for bot '{$botId}' chat {$chatId}"
            : "for bot '{$botId}' (all chats)";
    }
}
