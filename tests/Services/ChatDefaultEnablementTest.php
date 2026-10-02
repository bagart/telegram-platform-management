<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Wrappers\ASKCacheWrapper;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramBot\Modules\TgModuleRegistry;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Models\TgModuleEnablement;
use BAGArt\TelegramBotManagement\Services\TgModuleEnablementService;

/**
 * Q11 legacy parity for the enablement driver: defaultChatEnabled=false turns
 * the chat scope into an opt-in view (an explicit chat row only) while the bot
 * scope keeps the platform → bot → defaultEnabled cascade;
 * defaultChatEnabled=true keeps the full legacy chain unchanged.
 */
const CHAT_OPT_IN_MODULE = 'chat_opt_in_fixture';
const CHAT_CASCADE_MODULE = 'chat_cascade_fixture';

function chatDefaultService(): TgModuleEnablementService
{
    return new TgModuleEnablementService(
        moduleRegistry: app(TgModuleRegistry::class),
        cache: app(ASKCacheWrapper::class),
        ttlSeconds: 300,
    );
}

beforeEach(function () {
    config(['cache.default' => 'array']);
    config(['tg_modules.enablement_driver' => 'legacy']);
    app(TgModuleRegistry::class)->add(new TgModuleDescriptor(
        CHAT_OPT_IN_MODULE,
        'Chat Opt-In Fixture',
        '1.0.0',
        defaultEnabled: true,
        defaultChatEnabled: false,
    ));
    app(TgModuleRegistry::class)->add(new TgModuleDescriptor(
        CHAT_CASCADE_MODULE,
        'Chat Cascade Fixture',
        '1.0.0',
        defaultEnabled: true,
    ));
    TgBot::create(['bot_id' => 'bot1', 'token' => 't:1']);
});

describe('chat scope of a defaultChatEnabled=false module is opt-in (Q11)', function () {
    it('stays OFF without rows while the bot scope stays ON', function () {
        $svc = chatDefaultService();

        // Same instance: the chat-scope and null-chat decision maps must not
        // collide in the memo/cache keys.
        expect($svc->isEnabled(CHAT_OPT_IN_MODULE, 'bot1', 100))->toBeFalse()
            ->and($svc->isEnabled(CHAT_OPT_IN_MODULE, 'bot1', null))->toBeTrue()
            ->and($svc->isEnabled(CHAT_OPT_IN_MODULE, 'bot1'))->toBeTrue();
    });

    it('turns ON only through an explicit chat row, isolated per chat', function () {
        TgModuleEnablement::factory()->forChat('bot1', 100)->create([
            'module_id' => CHAT_OPT_IN_MODULE,
            'is_enabled' => true,
        ]);

        $svc = chatDefaultService();

        expect($svc->isEnabled(CHAT_OPT_IN_MODULE, 'bot1', 100))->toBeTrue()
            ->and($svc->isEnabled(CHAT_OPT_IN_MODULE, 'bot1', 200))->toBeFalse();
    });

    it('ignores an enabled bot row at chat scope but honors it at bot scope', function () {
        TgModuleEnablement::factory()->forBot('bot1')->create([
            'module_id' => CHAT_OPT_IN_MODULE,
            'is_enabled' => true,
        ]);

        $svc = chatDefaultService();

        expect($svc->isEnabled(CHAT_OPT_IN_MODULE, 'bot1', 100))->toBeFalse()
            ->and($svc->isEnabled(CHAT_OPT_IN_MODULE, 'bot1', null))->toBeTrue();
    });

    it('ignores an enabled platform row at chat scope', function () {
        TgModuleEnablement::factory()->platform()->create([
            'module_id' => CHAT_OPT_IN_MODULE,
            'is_enabled' => true,
        ]);

        $svc = chatDefaultService();

        expect($svc->isEnabled(CHAT_OPT_IN_MODULE, 'bot1', 100))->toBeFalse()
            ->and($svc->isEnabled(CHAT_OPT_IN_MODULE, 'bot1', null))->toBeTrue();
    });
});

describe('bot-level scope (null chatId)', function () {
    it('falls back to the descriptor default when no rows exist', function () {
        $svc = chatDefaultService();

        expect($svc->isEnabled(CHAT_OPT_IN_MODULE, 'bot1', null))->toBeTrue()
            ->and($svc->isEnabled(CHAT_CASCADE_MODULE, 'bot1', null))->toBeTrue();
    });

    it('honors an explicit bot row and never lets chat rows leak into it', function () {
        TgModuleEnablement::factory()->forBot('bot1')->create([
            'module_id' => CHAT_OPT_IN_MODULE,
            'is_enabled' => false,
        ]);
        TgModuleEnablement::factory()->forChat('bot1', 100)->create([
            'module_id' => CHAT_CASCADE_MODULE,
            'is_enabled' => false,
        ]);

        $svc = chatDefaultService();

        expect($svc->isEnabled(CHAT_OPT_IN_MODULE, 'bot1', null))->toBeFalse()
            ->and($svc->isEnabled(CHAT_CASCADE_MODULE, 'bot1', null))->toBeTrue();
    });
});

describe('defaultChatEnabled=true keeps the full legacy cascade', function () {
    it('falls back to the descriptor default with no rows', function () {
        $svc = chatDefaultService();

        expect($svc->isEnabled(CHAT_CASCADE_MODULE, 'bot1', 100))->toBeTrue()
            ->and($svc->isEnabled(CHAT_CASCADE_MODULE, 'bot1', null))->toBeTrue();
    });

    it('reaches the platform row when no narrower rows exist', function () {
        TgModuleEnablement::factory()->platform()->create([
            'module_id' => CHAT_CASCADE_MODULE,
            'is_enabled' => false,
        ]);

        $svc = chatDefaultService();

        expect($svc->isEnabled(CHAT_CASCADE_MODULE, 'bot1', 100))->toBeFalse()
            ->and($svc->isEnabled(CHAT_CASCADE_MODULE, 'bot1', null))->toBeFalse();
    });

    it('lets the bot row override the platform row at both scopes', function () {
        TgModuleEnablement::factory()->platform()->create([
            'module_id' => CHAT_CASCADE_MODULE,
            'is_enabled' => false,
        ]);
        TgModuleEnablement::factory()->forBot('bot1')->create([
            'module_id' => CHAT_CASCADE_MODULE,
            'is_enabled' => true,
        ]);

        $svc = chatDefaultService();

        expect($svc->isEnabled(CHAT_CASCADE_MODULE, 'bot1', 100))->toBeTrue()
            ->and($svc->isEnabled(CHAT_CASCADE_MODULE, 'bot1', null))->toBeTrue();
    });

    it('lets the chat row win over bot and platform rows', function () {
        TgModuleEnablement::factory()->platform()->create([
            'module_id' => CHAT_CASCADE_MODULE,
            'is_enabled' => true,
        ]);
        TgModuleEnablement::factory()->forBot('bot1')->create([
            'module_id' => CHAT_CASCADE_MODULE,
            'is_enabled' => true,
        ]);
        TgModuleEnablement::factory()->forChat('bot1', 100)->create([
            'module_id' => CHAT_CASCADE_MODULE,
            'is_enabled' => false,
        ]);

        $svc = chatDefaultService();

        expect($svc->isEnabled(CHAT_CASCADE_MODULE, 'bot1', 100))->toBeFalse()
            ->and($svc->isEnabled(CHAT_CASCADE_MODULE, 'bot1', null))->toBeTrue();
    });
});

describe('invalidation of the chat opt-in view', function () {
    it('makes refresh(bot, chat) pick up a newly written chat row', function () {
        $reader = chatDefaultService();
        expect($reader->isEnabled(CHAT_OPT_IN_MODULE, 'bot1', 100))->toBeFalse();

        chatDefaultService()->setEnabled(CHAT_OPT_IN_MODULE, 'bot1', 100, true);
        expect($reader->isEnabled(CHAT_OPT_IN_MODULE, 'bot1', 100))->toBeFalse(); // stale in-memory

        $reader->refresh('bot1', 100);

        expect($reader->isEnabled(CHAT_OPT_IN_MODULE, 'bot1', 100))->toBeTrue();
    });
});
