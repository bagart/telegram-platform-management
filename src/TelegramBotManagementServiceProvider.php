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
        \BAGArt\TelegramBotManagement\Commands\TgModulesListCommand::class,
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
        // Concrete class is the single-writer injection point (CLI commands
        // type-hint it); contract bindings share this exact instance.
        $this->app->singleton(
            \BAGArt\TelegramBotManagement\Services\TgModuleEnablementService::class,
            fn ($app): \BAGArt\TelegramBotManagement\Services\TgModuleEnablementService => new \BAGArt\TelegramBotManagement\Services\TgModuleEnablementService(
                moduleRegistry: $app->make(\BAGArt\TelegramBot\Modules\TgModuleRegistry::class),
                cache: $app->make(\BAGArt\AsyncKernel\Wrappers\ASKCacheWrapper::class),
                logger: $app->make(\BAGArt\AsyncKernel\Wrappers\ASKLogWrapper::class),
            ),
        );

        $this->app->singleton(
            \BAGArt\TelegramBot\Contracts\Modules\ModuleEnablementContract::class,
            fn ($app): \BAGArt\TelegramBotManagement\Services\TgModuleEnablementService => $app->make(
                \BAGArt\TelegramBotManagement\Services\TgModuleEnablementService::class
            ),
        );

        // Same instance as the enablement service — settings share its caches
        $this->app->singleton(
            \BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract::class,
            fn ($app): \BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract => $app->make(
                \BAGArt\TelegramBot\Contracts\Modules\ModuleEnablementContract::class
            ),
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
