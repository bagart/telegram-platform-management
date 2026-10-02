<?php

declare(strict_types=1);

use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramBot\Modules\TgModuleRegistry;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Models\TgModuleEnablement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);
    config(['tg_modules.enablement_driver' => 'legacy']);
});

function toggleRegisterModule(TgModuleRegistry $registry, string $id = 'toggle-mod'): void
{
    $registry->add(new TgModuleDescriptor(
        id: $id,
        name: ucfirst($id),
        version: '1.0.0',
        defaultEnabled: true,
    ));
}

describe('TgModuleToggleCommand', function () {
    describe('error paths', function () {
        it('fails for unknown module', function () {
            $this->artisan('tg:module:enable', ['module' => 'nonexistent'])
                ->assertFailed();
        });

        it('enable succeeds for known module at platform scope', function () {
            toggleRegisterModule(app(TgModuleRegistry::class));

            $this->artisan('tg:module:enable', ['module' => 'toggle-mod'])
                ->expectsOutput("Enabled module 'toggle-mod' platform-wide (bot_id NULL).")
                ->assertOk();
        });

        it('disable succeeds for known module at platform scope', function () {
            toggleRegisterModule(app(TgModuleRegistry::class));

            $this->artisan('tg:module:disable', ['module' => 'toggle-mod'])
                ->expectsOutput("Disabled module 'toggle-mod' platform-wide (bot_id NULL).")
                ->assertOk();
        });

        it('writes correct is_enabled for enable', function () {
            toggleRegisterModule(app(TgModuleRegistry::class));

            $this->artisan('tg:module:enable', ['module' => 'toggle-mod']);

            $row = TgModuleEnablement::query()->whereNull('bot_id')->whereNull('chat_id')->sole();
            expect($row->is_enabled)->toBeTrue();
        });

        it('writes correct is_enabled for disable', function () {
            toggleRegisterModule(app(TgModuleRegistry::class));

            $this->artisan('tg:module:disable', ['module' => 'toggle-mod']);

            $row = TgModuleEnablement::query()->whereNull('bot_id')->whereNull('chat_id')->sole();
            expect($row->is_enabled)->toBeFalse();
        });

        it('chat without --bot fails', function () {
            toggleRegisterModule(app(TgModuleRegistry::class));

            $this->artisan('tg:module:enable', ['module' => 'toggle-mod', '--chat' => '100'])
                ->assertFailed();
        });

        it('bot-level toggle works', function () {
            toggleRegisterModule(app(TgModuleRegistry::class));
            TgBot::create(['bot_id' => 'b1', 'token' => 't:1']);

            $this->artisan('tg:module:enable', [
                'module' => 'toggle-mod',
                '--bot' => 'b1',
            ])->assertOk();

            $row = TgModuleEnablement::query()->where('bot_id', 'b1')->whereNull('chat_id')->sole();
            expect($row->is_enabled)->toBeTrue();
        });

        it('chat-level toggle works', function () {
            toggleRegisterModule(app(TgModuleRegistry::class));
            TgBot::create(['bot_id' => 'b1', 'token' => 't:1']);

            $this->artisan('tg:module:enable', [
                'module' => 'toggle-mod',
                '--bot' => 'b1',
                '--chat' => '200',
            ])->assertOk();

            $row = TgModuleEnablement::query()->where('bot_id', 'b1')->where('chat_id', 200)->sole();
            expect($row->is_enabled)->toBeTrue();
        });

        it('scope description shows bot and chat', function () {
            toggleRegisterModule(app(TgModuleRegistry::class));
            TgBot::create(['bot_id' => 'b2', 'token' => 't:2']);

            $this->artisan('tg:module:enable', [
                'module' => 'toggle-mod',
                '--bot' => 'b2',
                '--chat' => '300',
            ])->expectsOutput("Enabled module 'toggle-mod' for bot 'b2' chat 300.")
                ->assertOk();
        });

        it('scope description shows bot without chat', function () {
            toggleRegisterModule(app(TgModuleRegistry::class));
            TgBot::create(['bot_id' => 'b3', 'token' => 't:3']);

            $this->artisan('tg:module:enable', [
                'module' => 'toggle-mod',
                '--bot' => 'b3',
            ])->expectsOutput("Enabled module 'toggle-mod' for bot 'b3' (all chats).")
                ->assertOk();
        });
    });

    describe('engine driver paths', function () {
        it('fails when engine driver and no ModuleActivationService', function () {
            config(['tg_modules.enablement_driver' => 'engine']);
            toggleRegisterModule(app(TgModuleRegistry::class));

            $this->app->forgetInstance(\BAGArt\TelegramModuleEngine\Activation\ModuleActivationService::class);

            $this->artisan('tg:module:enable', ['module' => 'toggle-mod', '--bot' => 'b1'])
                ->assertFailed();
        });

        it('fails with engine driver when --chat is provided', function () {
            config(['tg_modules.enablement_driver' => 'engine']);
            toggleRegisterModule(app(TgModuleRegistry::class));
            TgBot::create(['bot_id' => 'b1', 'token' => 't:1']);

            $this->artisan('tg:module:enable', [
                'module' => 'toggle-mod',
                '--bot' => 'b1',
                '--chat' => '100',
            ])->assertFailed();
        });

        it('fails with engine driver when --bot is omitted', function () {
            config(['tg_modules.enablement_driver' => 'engine']);
            toggleRegisterModule(app(TgModuleRegistry::class));

            $this->artisan('tg:module:enable', ['module' => 'toggle-mod'])
                ->assertFailed();
        });
    });
});
