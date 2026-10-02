<?php

declare(strict_types=1);

use BAGArt\TelegramBotManagement\Enums\BotAvailability;
use BAGArt\TelegramBotManagement\Enums\BotClass;
use BAGArt\TelegramBotManagement\Models\TgBot;

describe('TgBot model', function () {
    it('uses bot_id as primary key', function () {
        $model = new TgBot();

        expect($model->getKeyName())->toBe('bot_id');
    });

    it('is non-incrementing', function () {
        $model = new TgBot();

        expect($model->getIncrementing())->toBeFalse();
    });

    it('has string key type', function () {
        $model = new TgBot();

        expect($model->getKeyType())->toBe('string');
    });

    it('has correct fillable attributes', function () {
        $model = new TgBot();

        expect($model->getFillable())->toBe([
            'bot_id',
            'token',
            'secret_token',
            'class',
            'availability',
            'external_token',
        ]);
    });

    it('hides token, secret_token and external_token', function () {
        $model = new TgBot();

        expect($model->getHidden())->toBe([
            'token',
            'secret_token',
            'external_token',
        ]);
    });
});

describe('TgBot ecosystem columns', function () {
    it('defaults to a platform/public bot through the factory', function () {
        $bot = TgBot::factory()->create();

        expect($bot->class)->toBe(BotClass::Platform)
            ->and($bot->availability)->toBe(BotAvailability::Public)
            ->and($bot->external_token)->toBeNull();
    });

    it('casts class and availability to enums', function () {
        $bot = TgBot::factory()->privateCatalog()->create();

        expect($bot->class)->toBe(BotClass::Platform)
            ->and($bot->availability)->toBe(BotAvailability::Private);
    });

    it('encrypts external_token at rest and decrypts on access', function () {
        $bot = TgBot::factory()->external()->create();
        $expected = $bot->bot_id.':'.str_repeat('e', 35);

        expect($bot->external_token)->toBe($expected)
            ->and($bot->getAttributes()['external_token'])->not->toBe($expected)
            ->and($bot->getAttributes()['external_token'])->not->toContain($expected);

        $fresh = TgBot::query()->find($bot->bot_id);
        expect($fresh->external_token)->toBe($expected);
    });

    it('never serializes secrets', function () {
        $bot = TgBot::factory()->external()->create();
        $array = $bot->toArray();

        expect($array)->not->toHaveKey('token')
            ->and($array)->not->toHaveKey('secret_token')
            ->and($array)->not->toHaveKey('external_token');
    });

    it('keeps legacy rows readable with default ecosystem values', function () {
        $bot = TgBot::create(['bot_id' => 'legacy001', 'token' => 'legacy001:'.str_repeat('z', 35)]);

        $fresh = TgBot::query()->find($bot->bot_id);
        expect($fresh->class)->toBe(BotClass::Platform)
            ->and($fresh->availability)->toBe(BotAvailability::Public);
    });
});
