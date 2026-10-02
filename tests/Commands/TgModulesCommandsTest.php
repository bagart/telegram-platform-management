<?php

declare(strict_types=1);

use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramBot\Modules\TgModuleRegistry;
use BAGArt\TelegramBotManagement\Models\TgModuleEnablement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);
});

function registerTestModule(TgModuleRegistry $registry, string $id = 'test-mod', array $opts = []): void
{
    $registry->add(new TgModuleDescriptor(
        id: $id,
        name: ucfirst($id),
        version: $opts['version'] ?? '1.0.0',
        defaultEnabled: $opts['defaultEnabled'] ?? true,
        requiresModules: $opts['requiresModules'] ?? [],
        conflictsWith: $opts['conflictsWith'] ?? [],
    ));
}

describe('TgModulesListCommand', function () {
    it('lists configured modules from engine registry', function () {
        $this->artisan('tg:modules:list')
            ->assertOk();
    });

    it('lists modules in table format', function () {
        registerTestModule(app(TgModuleRegistry::class), 'my-module');

        $this->artisan('tg:modules:list')
            ->assertOk();
    });

    it('lists multiple modules', function () {
        $registry = app(TgModuleRegistry::class);
        registerTestModule($registry, 'mod-a');
        registerTestModule($registry, 'mod-b');

        $this->artisan('tg:modules:list')
            ->assertOk();
    });
});

describe('TgModulesDoctorCommand', function () {
    it('reports healthy when no orphans and no conflicts', function () {
        registerTestModule(app(TgModuleRegistry::class), 'healthy-mod');

        $this->artisan('tg:modules:doctor')
            ->expectsOutput('Module configuration is healthy: enablements, requirements and conflicts are consistent.')
            ->assertOk();
    });

    it('detects orphan enablement rows', function () {
        $registry = app(TgModuleRegistry::class);
        registerTestModule($registry, 'known-mod');

        TgModuleEnablement::create([
            'module_id' => 'unknown-mod',
            'is_enabled' => true,
        ]);

        $this->artisan('tg:modules:doctor')
            ->assertFailed();
    });

    it('detects unmet module requirement', function () {
        $registry = app(TgModuleRegistry::class);
        registerTestModule($registry, 'consumer', [
            'requiresModules' => ['missing-dep' => '>=1.0.0'],
        ]);

        $this->artisan('tg:modules:doctor')
            ->assertFailed();
    });

    it('detects version constraint failure', function () {
        $registry = app(TgModuleRegistry::class);
        registerTestModule($registry, 'dep', ['version' => '0.5.0']);
        registerTestModule($registry, 'consumer', [
            'requiresModules' => ['dep' => '>=1.0.0'],
        ]);

        $this->artisan('tg:modules:doctor')
            ->assertFailed();
    });

    it('accepts wildcard version constraint', function () {
        $registry = app(TgModuleRegistry::class);
        registerTestModule($registry, 'dep', ['version' => '0.1.0']);
        registerTestModule($registry, 'consumer', [
            'requiresModules' => ['dep' => '*'],
        ]);

        $this->artisan('tg:modules:doctor')
            ->expectsOutput('Module configuration is healthy: enablements, requirements and conflicts are consistent.')
            ->assertOk();
    });

    it('detects mutual conflicts', function () {
        $registry = app(TgModuleRegistry::class);
        registerTestModule($registry, 'mod-a', ['conflictsWith' => ['mod-b']]);
        registerTestModule($registry, 'mod-b');

        $this->artisan('tg:modules:doctor')
            ->assertFailed();
    });

    it('does not report conflict when conflicting module is not registered', function () {
        $registry = app(TgModuleRegistry::class);
        registerTestModule($registry, 'mod-a', ['conflictsWith' => ['mod-b']]);

        $this->artisan('tg:modules:doctor')
            ->expectsOutput('Module configuration is healthy: enablements, requirements and conflicts are consistent.')
            ->assertOk();
    });

    it('accepts legacy list-style requiresModules', function () {
        $registry = app(TgModuleRegistry::class);
        registerTestModule($registry, 'dep', ['version' => '2.0.0']);
        registerTestModule($registry, 'consumer', [
            'requiresModules' => ['dep@>=1.0.0'],
        ]);

        $this->artisan('tg:modules:doctor')
            ->expectsOutput('Module configuration is healthy: enablements, requirements and conflicts are consistent.')
            ->assertOk();
    });
});
