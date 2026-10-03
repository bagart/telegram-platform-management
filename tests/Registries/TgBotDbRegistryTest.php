<?php

declare(strict_types=1);

use BAGArt\TelegramBot\Contracts\BotServices\TgBotRegistryContract;
use BAGArt\TelegramBot\Exceptions\TgTechnicalWithEntityException;
use BAGArt\TelegramBot\TgIntegration\BotSecretDTO;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Registries\TgBotDbRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('TgBotDbRegistry', function () {
    it('implements TgBotRegistryContract', function () {
        // class_implements instead of arch toImplement: vendor-mode CI
        // cannot evaluate toImplement on platform classes.
        expect(class_implements(TgBotDbRegistry::class))
            ->toContain(TgBotRegistryContract::class);
    });

    it('register() throws TgTechnicalWithEntityException', function () {
        $registry = TgBotDbRegistry::build();

        $registry->register(new BotSecretDTO('123456:ABC-DEF'));
    })->throws(TgTechnicalWithEntityException::class, 'Use TelegramBotManager to register new Bots');

    it('build() returns a TgBotDbRegistry instance', function () {
        expect(TgBotDbRegistry::build())->toBeInstanceOf(TgBotDbRegistry::class);
    });

    it('getBot() returns BotSecretDTO when bot exists', function () {
        TgBot::create(['bot_id' => 'bot1', 'token' => '123456:tok123', 'secret_token' => 'sec456']);
        $registry = TgBotDbRegistry::build();

        $result = $registry->getBot('bot1');

        expect($result)->toBeInstanceOf(BotSecretDTO::class)
            ->and($result->token())->toBe('123456:tok123')
            ->and($result->secret())->toBe('sec456');
    });

    it('getBot() returns null when bot does not exist', function () {
        $registry = TgBotDbRegistry::build();

        expect($registry->getBot('nonexistent'))->toBeNull();
    });

    it('has() returns true when bot exists', function () {
        TgBot::create(['bot_id' => 'bot2', 'token' => '123456:tok789']);
        $registry = TgBotDbRegistry::build();

        expect($registry->has('bot2'))->toBeTrue();
    });

    it('has() returns false when bot does not exist', function () {
        $registry = TgBotDbRegistry::build();

        expect($registry->has('nonexistent'))->toBeFalse();
    });
});
