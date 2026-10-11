<?php

declare(strict_types=1);

use BAGArt\TelegramBotManagement\Commands\TgBotManagerInit;
use BAGArt\TelegramBotManagement\Events\BotCreated;
use BAGArt\TelegramBotManagement\Models\TgBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

describe('TgBotManagerInit', function () {
    it('extracts bot_id from token', function () {
        $method = new ReflectionMethod(TgBotManagerInit::class, 'extractBotId');
        $method->setAccessible(true);
        $command = new TgBotManagerInit();

        expect($method->invoke($command, '123456:ABC-DEF_GHI'))->toBe('123456')
            ->and($method->invoke($command, '987654321:token_with_underscores'))->toBe('987654321');
    });

    it('dispatches BotCreated with the bot id on creation', function () {
        Event::fake([BotCreated::class]);

        $this->artisan('tgbm:init', ['--token' => '123456:ABC-DEF_GHI'])
            ->assertOk();

        expect(TgBot::where('bot_id', '123456')->exists())->toBeTrue();

        Event::assertDispatched(BotCreated::class, function (BotCreated $event) {
            return $event->botId === '123456'
                && $event->secretToken === ''
                && $event->actorId === null
                && $event->actorType === null;
        });
    });

    it('does not dispatch BotCreated when the bot already exists', function () {
        TgBot::create(['bot_id' => '123456', 'token' => '123456:ABC-DEF_GHI']);

        Event::fake([BotCreated::class]);

        $this->artisan('tgbm:init', ['--token' => '123456:ABC-DEF_GHI'])
            ->assertOk();

        Event::assertNotDispatched(BotCreated::class);
    });
});
