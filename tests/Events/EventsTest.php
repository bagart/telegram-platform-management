<?php

declare(strict_types=1);

use BAGArt\TelegramBotManagement\Events\BotCreated;
use BAGArt\TelegramBotManagement\Events\BotDeleted;
use BAGArt\TelegramBotManagement\Events\BotModuleSettingChanged;
use BAGArt\TelegramBotManagement\Events\BotTokenRotated;

describe('Domain events', function () {
    describe('BotCreated', function () {
        it('is a final readonly DTO', function () {
            $reflection = new ReflectionClass(BotCreated::class);

            expect($reflection->isFinal())->toBeTrue()
                ->and($reflection->isReadOnly())->toBeTrue();
        });

        it('carries botId, secretToken, actorId, actorType', function () {
            $event = new BotCreated(
                botId: '123456',
                secretToken: 'secret',
                actorId: 'user1',
                actorType: 'admin',
            );

            expect($event->botId)->toBe('123456')
                ->and($event->secretToken)->toBe('secret')
                ->and($event->actorId)->toBe('user1')
                ->and($event->actorType)->toBe('admin');
        });

        it('defaults actor fields to null', function () {
            $event = new BotCreated(botId: '123', secretToken: 's');

            expect($event->actorId)->toBeNull()
                ->and($event->actorType)->toBeNull();
        });

        it('uses Dispatchable trait', function () {
            $reflection = new ReflectionClass(BotCreated::class);

            expect($reflection->getTraits())->toHaveKey('Illuminate\Foundation\Events\Dispatchable');
        });
    });

    describe('BotDeleted', function () {
        it('is a final readonly DTO', function () {
            $reflection = new ReflectionClass(BotDeleted::class);

            expect($reflection->isFinal())->toBeTrue()
                ->and($reflection->isReadOnly())->toBeTrue();
        });

        it('carries botId, actorId, actorType', function () {
            $event = new BotDeleted(
                botId: '789',
                actorId: 'u1',
                actorType: 'system',
            );

            expect($event->botId)->toBe('789')
                ->and($event->actorId)->toBe('u1')
                ->and($event->actorType)->toBe('system');
        });
    });

    describe('BotTokenRotated', function () {
        it('is a final readonly DTO', function () {
            $reflection = new ReflectionClass(BotTokenRotated::class);

            expect($reflection->isFinal())->toBeTrue()
                ->and($reflection->isReadOnly())->toBeTrue();
        });

        it('carries botId, actorId, actorType', function () {
            $event = new BotTokenRotated(botId: '456');

            expect($event->botId)->toBe('456')
                ->and($event->actorId)->toBeNull()
                ->and($event->actorType)->toBeNull();
        });
    });

    describe('BotModuleSettingChanged', function () {
        it('is a final readonly DTO', function () {
            $reflection = new ReflectionClass(BotModuleSettingChanged::class);

            expect($reflection->isFinal())->toBeTrue()
                ->and($reflection->isReadOnly())->toBeTrue();
        });

        it('carries all module setting fields', function () {
            $event = new BotModuleSettingChanged(
                moduleId: 'antispam',
                botId: 'bot1',
                chatId: 100,
                isEnabled: true,
                settings: ['threshold' => 5],
                actorId: 'admin',
                actorType: 'user',
            );

            expect($event->moduleId)->toBe('antispam')
                ->and($event->botId)->toBe('bot1')
                ->and($event->chatId)->toBe(100)
                ->and($event->isEnabled)->toBeTrue()
                ->and($event->settings)->toBe(['threshold' => 5])
                ->and($event->actorId)->toBe('admin')
                ->and($event->actorType)->toBe('user');
        });

        it('allows null botId and chatId for platform scope', function () {
            $event = new BotModuleSettingChanged(
                moduleId: 'mod1',
                botId: null,
                chatId: null,
                isEnabled: false,
                settings: null,
            );

            expect($event->botId)->toBeNull()
                ->and($event->chatId)->toBeNull()
                ->and($event->isEnabled)->toBeFalse()
                ->and($event->settings)->toBeNull();
        });
    });
});
