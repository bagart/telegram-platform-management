<?php

declare(strict_types=1);

use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Models\TgChat;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('TgChat model', function () {
    it('uses chat_id as primary key', function () {
        $model = new TgChat();

        expect($model->getKeyName())->toBe('chat_id');
    });

    it('is non-incrementing', function () {
        $model = new TgChat();

        expect($model->getIncrementing())->toBeFalse();
    });

    it('has correct fillable attributes', function () {
        $model = new TgChat();

        expect($model->getFillable())->toBe([
            'bot_id',
            'chat_id',
            'type',
            'title',
            'username',
            'is_active',
            'deactivate_reason',
            'member_count',
            'last_seen_at',
        ]);
    });

    it('has bot relation', function () {
        $model = new TgChat();

        expect($model->bot())->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    });

    it('casts is_active to boolean', function () {
        TgBot::create(['bot_id' => 'bot1', 'token' => 't:1']);

        $chat = TgChat::create([
            'bot_id' => 'bot1',
            'chat_id' => 100,
            'type' => 'group',
            'is_active' => true,
        ]);

        expect($chat->is_active)->toBeTrue();
    });

    it('defaults is_active to true', function () {
        TgBot::create(['bot_id' => 'bot1', 'token' => 't:1']);

        $chat = TgChat::create([
            'bot_id' => 'bot1',
            'chat_id' => 200,
            'type' => 'private',
        ]);

        expect($chat->is_active)->toBeTrue();
    });

    it('belongs to a TgBot', function () {
        TgBot::create(['bot_id' => 'bot1', 'token' => 't:1']);

        $chat = TgChat::create([
            'bot_id' => 'bot1',
            'chat_id' => 300,
            'type' => 'supergroup',
            'title' => 'Test Group',
        ]);

        expect($chat->bot)->toBeInstanceOf(TgBot::class)
            ->and($chat->bot->bot_id)->toBe('bot1');
    });
});
