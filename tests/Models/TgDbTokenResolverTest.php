<?php

declare(strict_types=1);

use BAGArt\TelegramBot\Contracts\Outbound\BotTokenResolverContract;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Models\TgDbTokenResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('TgDbTokenResolver', function () {
    it('implements BotTokenResolverContract', function () {
        // class_implements instead of arch toImplement: vendor-mode CI
        // cannot evaluate toImplement on platform classes.
        expect(class_implements(TgDbTokenResolver::class))
            ->toContain(BotTokenResolverContract::class);
    });

    it('is a readonly class', function () {
        $reflection = new ReflectionClass(TgDbTokenResolver::class);

        expect($reflection->isReadOnly())->toBeTrue()
            ->and($reflection->isFinal())->toBeTrue();
    });

    it('resolves token from DB', function () {
        TgBot::create(['bot_id' => 'bot1', 'token' => 'tok123']);
        $resolver = new TgDbTokenResolver();

        expect($resolver->resolve('bot1'))->toBe('tok123');
    });

    it('throws RuntimeException when bot not found', function () {
        $resolver = new TgDbTokenResolver();

        $resolver->resolve('nonexistent');
    })->throws(RuntimeException::class, 'Bot token not found for botId: nonexistent');

    it('throws RuntimeException when token is empty', function () {
        TgBot::create(['bot_id' => 'bot2', 'token' => '']);
        $resolver = new TgDbTokenResolver();

        $resolver->resolve('bot2');
    })->throws(RuntimeException::class, 'Bot token is empty for botId: bot2');
});
