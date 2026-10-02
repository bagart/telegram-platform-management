<?php

declare(strict_types=1);

use BAGArt\TelegramBotManagement\Enums\EntityKind;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Models\TgEntity;
use BAGArt\TelegramBotManagement\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

describe('TgEntity model', function () {
    it('has correct fillable attributes', function () {
        $model = new TgEntity();

        expect($model->getFillable())->toBe([
            'workspace_id',
            'kind',
            'external_id',
            'bot_id',
            'title',
            'username',
            'linked_by',
            'linked_at',
        ]);
    });

    it('casts kind to EntityKind and numeric fields to int', function () {
        $entity = TgEntity::factory()->create();

        expect($entity->kind)->toBe(EntityKind::Channel)
            ->and($entity->external_id)->toBeInt()
            ->and($entity->linked_at)->not->toBeNull();
    });

    it('has workspace and bot relations', function () {
        $bot = TgBot::factory()->create();
        $botEntity = TgEntity::factory()->bot($bot->bot_id)->create();
        $channelEntity = TgEntity::factory()->create();

        expect($botEntity->workspace())->toBeInstanceOf(BelongsTo::class)
            ->and($botEntity->workspace)->toBeInstanceOf(Workspace::class)
            ->and($botEntity->bot)->toBeInstanceOf(TgBot::class)
            ->and($channelEntity->bot)->toBeNull()
            ->and($channelEntity->workspace)->toBeInstanceOf(Workspace::class);
    });

    it('creates channel entities through the factory with a workspace', function () {
        $entity = TgEntity::factory()->create();

        expect($entity->id)->toBeInt()
            ->and($entity->workspace_id)->toBeInt()
            ->and(Workspace::query()->find($entity->workspace_id))->not->toBeNull()
            ->and($entity->bot_id)->toBeNull()
            ->and($entity->external_id)->toBeLessThan(0);
    });
});

describe('TgEntity storage constraints', function () {
    it('rejects a bot-kind row carrying an external_id at the database level', function () {
        $workspace = Workspace::create(['name' => 'Constraint', 'slug' => 'constraint']);

        expect(fn (): mixed => DB::table('tg_entities')->insert([
            'workspace_id' => $workspace->id,
            'kind' => 'bot',
            'external_id' => 123,
            'bot_id' => '123456789',
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('rejects an unknown kind at the database level', function () {
        $workspace = Workspace::create(['name' => 'Kind', 'slug' => 'kind']);

        expect(fn (): mixed => DB::table('tg_entities')->insert([
            'workspace_id' => $workspace->id,
            'kind' => 'group',
            'external_id' => -100,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });

    it('enforces uniqueness per (workspace, kind, target)', function () {
        $first = TgEntity::factory()->create();

        expect(fn (): mixed => DB::table('tg_entities')->insert([
            'workspace_id' => $first->workspace_id,
            'kind' => $first->kind->value,
            'external_id' => $first->external_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);
    });
});
