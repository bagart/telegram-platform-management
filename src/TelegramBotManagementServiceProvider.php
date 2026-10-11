<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement;

use Illuminate\Support\ServiceProvider;

class TelegramBotManagementServiceProvider extends ServiceProvider
{
    protected array $commands = [
        \BAGArt\TelegramBotManagement\Commands\TgBMMonitorCommand::class,
        \BAGArt\TelegramBotManagement\Commands\TgBMPollerCommand::class,
        \BAGArt\TelegramBotManagement\Commands\TgBotManagerMigrate::class,
        \BAGArt\TelegramBotManagement\Commands\TgBotManagerInit::class,
        \BAGArt\TelegramBotManagement\Commands\TgBMAuditCommand::class,
        \BAGArt\TelegramBotManagement\Commands\TgOutboundDaemonCommand::class,
        \BAGArt\TelegramBotManagement\Commands\TgOutboundMetricsCommand::class,
        \BAGArt\TelegramBotManagement\Commands\TgOutboundDlqCommand::class,
        \BAGArt\TelegramBotManagement\Commands\TgOutboundToolCommand::class,
        \BAGArt\TelegramBotManagement\Commands\TgModulesDoctorCommand::class,
        \BAGArt\TelegramBotManagement\Commands\TgModuleEnableCommand::class,
        \BAGArt\TelegramBotManagement\Commands\TgModuleDisableCommand::class,
    ];

    /**
     * Commands that only exist when laravel/mcp is installed. Registered
     * conditionally so the lib doesn't hard-fail if mcp is absent.
     */
    protected array $mcpCommands = [
        \BAGArt\TelegramBotManagement\Commands\TgMcpStartCommand::class,
    ];

    public function register(): void
    {
        // Concrete class is the single-writer injection point for the legacy
        // enablement table (CLI commands type-hint it); always available.
        $this->app->singleton(
            \BAGArt\TelegramBotManagement\Services\TgModuleEnablementService::class,
            fn ($app): \BAGArt\TelegramBotManagement\Services\TgModuleEnablementService => new \BAGArt\TelegramBotManagement\Services\TgModuleEnablementService(
                moduleRegistry: $app->make(\BAGArt\TelegramBot\Modules\TgModuleRegistry::class),
                cache: $app->make(\BAGArt\AsyncKernel\Wrappers\ASKCacheWrapper::class),
                logger: $app->make(\BAGArt\AsyncKernel\Wrappers\ASKLogWrapper::class),
            ),
        );

        // When enablement_driver='engine', the engine provider's
        // ModuleEnablementContract binding (EngineModuleEnablement over
        // bot_module_activations) wins — we skip our contract binding here
        // to avoid the management binding (registered later) overriding it.
        $driver = $this->app->make('config')->get('tg_modules.enablement_driver', 'legacy');
        if ($driver !== 'engine') {
            $this->app->singleton(
                \BAGArt\TelegramBot\Contracts\Modules\ModuleEnablementContract::class,
                fn ($app): \BAGArt\TelegramBotManagement\Services\TgModuleEnablementService => $app->make(
                    \BAGArt\TelegramBotManagement\Services\TgModuleEnablementService::class
                ),
            );
        }

        // Settings: legacy driver only. Under enablement_driver='engine' the
        // engine provider binds EngineSettingsAdapter over
        // bot_module_activations — settings migration is complete and the
        // legacy table is dropped by 2026_09_20_000002, so this legacy
        // binding exists for unmigrated dev databases and pinned tests.
        if ($driver !== 'engine') {
            $this->app->singleton(
                \BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract::class,
                fn ($app): \BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract => $app->make(
                    \BAGArt\TelegramBotManagement\Services\TgModuleEnablementService::class
                ),
            );
        }

        // Entity-add verification (ADR-002 SD2): probe against the lib API
        // client when telegram-bot-lib is present; otherwise fall back to a
        // stub that fails loudly instead of silently allowing anything.
        // Tests bind their own fake over this contract.
        $this->app->singleton(
            \BAGArt\TelegramBotManagement\Contracts\ChatAdministratorProbeContract::class,
            function ($app): \BAGArt\TelegramBotManagement\Contracts\ChatAdministratorProbeContract {
                if (interface_exists(\BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract::class)) {
                    return new \BAGArt\TelegramBotManagement\Services\HttpChatAdministratorProbe(
                        api: $app->make(\BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract::class),
                    );
                }

                return new class () implements \BAGArt\TelegramBotManagement\Contracts\ChatAdministratorProbeContract {
                    public function verify(string $botId, int $chatId, int $initiatorTgId): \BAGArt\TelegramBotManagement\DTO\EntityAddVerification
                    {
                        throw new \LogicException(
                            'ChatAdministratorProbeContract requires bagart/telegram-bot-lib (TgBotApiDTOClientContract is not installed).',
                        );
                    }
                };
            },
        );

        // Workspace administration (ADR-001): membership lifecycle + T2
        // grant management over the access module. The audit recorder is
        // resolved lazily against AuditSinkContract (bound only when the
        // audit module is installed).
        $this->app->singleton(
            \BAGArt\TelegramBotManagement\Services\WorkspaceAuditRecorder::class,
        );
        $this->app->singleton(
            \BAGArt\TelegramBotManagement\Services\WorkspaceAdminService::class,
        );
        $this->app->singleton(
            \BAGArt\TelegramBotManagement\Services\WorkspacePermissionService::class,
        );
        // Rights-source resolution for web surfaces (D7/D11): workspace
        // owner ↔ bot linkage, never tg_bot_owners.
        $this->app->singleton(
            \BAGArt\TelegramBotManagement\Services\BotOwnershipResolver::class,
        );

        $this->mergeConfigFrom(
            __DIR__.'/../config/tg-outbound-daemon.php',
            'tg-outbound-daemon',
        );

        $this->commands($this->commands);

        if (class_exists(\Laravel\Mcp\Facades\Mcp::class)) {
            $this->commands($this->mcpCommands);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'management');

        if (class_exists(\Laravel\Mcp\Facades\Mcp::class)) {
            \Laravel\Mcp\Facades\Mcp::local('tg-ops', \BAGArt\TelegramBotManagement\Mcp\TgOpsServer::class);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/tg-outbound-daemon.php' => config_path('tg-outbound-daemon.php'),
            ], 'tg-outbound-daemon-config');
        }
    }
}
