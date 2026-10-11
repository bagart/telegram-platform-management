<?php

declare(strict_types=1);

use BAGArt\TelegramBotAudit\Laravel\Listeners\RecordModuleLifecycleEvents;
use BAGArt\TelegramBotManagement\Events\BotCreated;
use BAGArt\TelegramBotManagement\Events\BotDeleted;
use BAGArt\TelegramBotManagement\Events\BotTokenRotated;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Services\TelegramBotManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

describe('TelegramBotManager', function () {
    describe('addBot()', function () {
        it('creates a TgBot in the database', function () {
            $bot = TelegramBotManager::addBot('123456:ABC-DEF');

            expect($bot)->toBeInstanceOf(TgBot::class)
                ->and($bot->bot_id)->toBe('123456')
                ->and($bot->token)->toBe('123456:ABC-DEF')
                ->and(TgBot::count())->toBe(1);
        });

        it('extracts bot_id as everything before the colon', function () {
            $bot = TelegramBotManager::addBot('987654321:my_long_token_with_underscores');

            expect($bot->bot_id)->toBe('987654321');
        });

        it('fires BotCreated event', function () {
            Event::fake([BotCreated::class]);

            TelegramBotManager::addBot('111222:SECRET');

            Event::assertDispatched(BotCreated::class, function (BotCreated $event) {
                return $event->botId === '111222';
            });
        });

        it('passes actorId and actorType to BotCreated event', function () {
            Event::fake([BotCreated::class]);

            TelegramBotManager::addBot(
                token: '333444:TOKEN',
                actorId: 'user-42',
                actorType: 'platform_admin',
            );

            Event::assertDispatched(BotCreated::class, function (BotCreated $event) {
                return $event->actorId === 'user-42'
                    && $event->actorType === 'platform_admin';
            });
        });

        it('default actor fields are null', function () {
            Event::fake([BotCreated::class]);

            TelegramBotManager::addBot('555666:TOKEN');

            Event::assertDispatched(BotCreated::class, function (BotCreated $event) {
                return $event->actorId === null
                    && $event->actorType === null;
            });
        });
    });

    // Wiring pin (B5): the audit provider's platform listener table must keep
    // these bindings — listener unit tests only invoke the handlers directly,
    // so without this assertion a provider edit could silently unbind them.
    describe('audit listener wiring', function () {
        it('binds bot lifecycle events to RecordModuleLifecycleEvents', function () {
            Event::fake();

            Event::assertListening(BotCreated::class, RecordModuleLifecycleEvents::class.'@handleBotCreated');
            Event::assertListening(BotDeleted::class, RecordModuleLifecycleEvents::class.'@handleBotDeleted');
            Event::assertListening(BotTokenRotated::class, RecordModuleLifecycleEvents::class.'@handleBotTokenRotated');
        });
    });
});
