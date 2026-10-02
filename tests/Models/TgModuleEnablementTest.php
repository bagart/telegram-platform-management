<?php

declare(strict_types=1);

use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Models\TgModuleEnablement;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('TgModuleEnablement model', function () {
    it('uses UUIDs as primary key', function () {
        $model = new TgModuleEnablement();

        expect($model->getKeyName())->toBe('id')
            ->and($model->getIncrementing())->toBeFalse()
            ->and($model->getKeyType())->toBe('string');
    });

    it('has correct fillable attributes', function () {
        $model = new TgModuleEnablement();

        expect($model->getFillable())->toBe([
            'bot_id',
            'chat_id',
            'module_id',
            'is_enabled',
            'module_settings',
        ]);
    });

    it('casts is_enabled to boolean and chat_id to integer', function () {
        TgBot::create(['bot_id' => 'bot1', 'token' => 't:1']);

        $enablement = TgModuleEnablement::create([
            'bot_id' => 'bot1',
            'chat_id' => 100,
            'module_id' => 'test-mod',
            'is_enabled' => true,
            'module_settings' => ['key' => 'value'],
        ]);

        expect($enablement->is_enabled)->toBeTrue()
            ->and($enablement->chat_id)->toBeInt()
            ->and($enablement->module_settings)->toBeArray()
            ->and($enablement->module_settings['key'])->toBe('value');
    });

    it('has bot relation', function () {
        $model = new TgModuleEnablement();

        expect($model->bot())->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    });

    it('uses HasFactory trait', function () {
        $reflection = new ReflectionClass(TgModuleEnablement::class);

        expect($reflection->getTraits())->toHaveKey('Illuminate\Database\Eloquent\Factories\HasFactory');
    });

    it('uses HasUuids trait', function () {
        $reflection = new ReflectionClass(TgModuleEnablement::class);

        expect($reflection->getTraits())->toHaveKey('Illuminate\Database\Eloquent\Concerns\HasUuids');
    });

    it('allows null bot_id and chat_id for platform scope', function () {
        $enablement = TgModuleEnablement::create([
            'bot_id' => null,
            'chat_id' => null,
            'module_id' => 'platform-mod',
            'is_enabled' => false,
        ]);

        expect($enablement->bot_id)->toBeNull()
            ->and($enablement->chat_id)->toBeNull();
    });

    it('generates a UUID on create', function () {
        $enablement = TgModuleEnablement::create([
            'module_id' => 'gen-test',
            'is_enabled' => true,
        ]);

        expect($enablement->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
    });
});
