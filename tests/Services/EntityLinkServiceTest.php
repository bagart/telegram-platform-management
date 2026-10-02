<?php

declare(strict_types=1);

use BAGArt\TelegramBotManagement\Enums\EntityKind;
use BAGArt\TelegramBotManagement\Exceptions\EntityException;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Models\TgEntity;
use BAGArt\TelegramBotManagement\Models\Workspace;
use BAGArt\TelegramBotManagement\Services\EntityLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function entityLinkWorkspace(string $slug): Workspace
{
    return Workspace::create(['name' => $slug, 'slug' => $slug]);
}

describe('EntityLinkService::link', function () {
    it('creates a channel entity scoped to the workspace', function () {
        $workspace = entityLinkWorkspace('ws-link-a');
        $service = new EntityLinkService();

        $entity = $service->link(
            workspaceId: $workspace->id,
            kind: EntityKind::Channel,
            externalId: -100500,
            botId: null,
            actorUserId: 42,
            title: 'News',
            username: 'news_channel',
        );

        expect($entity->workspace_id)->toBe($workspace->id)
            ->and($entity->kind)->toBe(EntityKind::Channel)
            ->and($entity->external_id)->toBe(-100500)
            ->and($entity->bot_id)->toBeNull()
            ->and($entity->linked_by)->toBe(42)
            ->and($entity->linked_at)->not->toBeNull()
            ->and($entity->title)->toBe('News')
            ->and($entity->username)->toBe('news_channel');
    });

    it('is idempotent: relinking returns the same row and refreshes title, username and linked_at', function () {
        $workspace = entityLinkWorkspace('ws-idem');
        $service = new EntityLinkService();

        $first = $service->link($workspace->id, EntityKind::Channel, -100123, null, 7, 'Old', 'old_user');
        TgEntity::query()->update(['linked_at' => now()->subDay()]);

        $second = $service->link($workspace->id, EntityKind::Channel, -100123, null, 9, 'New', 'new_user');

        expect($second->id)->toBe($first->id)
            ->and($second->title)->toBe('New')
            ->and($second->username)->toBe('new_user')
            ->and($second->linked_by)->toBe(7)
            ->and($second->linked_at->greaterThan(now()->subHour()))->toBeTrue()
            ->and(TgEntity::query()->count())->toBe(1);
    });

    it('links a platform bot and is idempotent for it', function () {
        $workspace = entityLinkWorkspace('ws-bot');
        $bot = TgBot::factory()->create();
        $service = new EntityLinkService();

        $first = $service->link($workspace->id, EntityKind::Bot, null, $bot->bot_id, 1, 'My Bot');
        $second = $service->link($workspace->id, EntityKind::Bot, null, $bot->bot_id, 1, 'My Bot Renamed');

        expect($first->bot_id)->toBe($bot->bot_id)
            ->and($first->external_id)->toBeNull()
            ->and($second->id)->toBe($first->id)
            ->and($second->title)->toBe('My Bot Renamed')
            ->and(TgEntity::query()->count())->toBe(1);
    });

    it('allows the same bot entity in several workspaces (shared catalog actor)', function () {
        $workspaceA = entityLinkWorkspace('ws-bot-a');
        $workspaceB = entityLinkWorkspace('ws-bot-b');
        $bot = TgBot::factory()->create();
        $service = new EntityLinkService();

        $service->link($workspaceA->id, EntityKind::Bot, null, $bot->bot_id, 1);
        $service->link($workspaceB->id, EntityKind::Bot, null, $bot->bot_id, 2);

        expect(TgEntity::query()->where('kind', EntityKind::Bot->value)->count())->toBe(2);
    });

    it('stores system-initiated links (actor 0) with linked_by null', function () {
        $workspace = entityLinkWorkspace('ws-system');
        $bot = TgBot::factory()->create();
        $service = new EntityLinkService();

        $entity = $service->link($workspace->id, EntityKind::Bot, null, $bot->bot_id, 0);

        expect($entity->linked_by)->toBeNull();
    });

    it('rejects kind/target mismatches', function (EntityKind $kind, ?int $externalId, ?string $botId) {
        $workspace = entityLinkWorkspace('ws-mismatch');
        $service = new EntityLinkService();

        expect(fn (): mixed => $service->link($workspace->id, $kind, $externalId, $botId, 1))
            ->toThrow(EntityException::class, 'does not match the supplied target');
    })->with([
        'bot kind with chat id' => [EntityKind::Bot, 123, null],
        'bot kind without bot id' => [EntityKind::Bot, null, null],
        'channel kind with bot id' => [EntityKind::Channel, -1001, '123456'],
        'channel kind without chat id' => [EntityKind::Channel, null, null],
        'chat group kind without chat id' => [EntityKind::ChatGroup, null, null],
    ]);

    it('rejects a bot the platform does not know', function () {
        $workspace = entityLinkWorkspace('ws-unknown-bot');
        $service = new EntityLinkService();

        expect(fn (): mixed => $service->link($workspace->id, EntityKind::Bot, null, '999999999', 1))
            ->toThrow(EntityException::class, 'does not exist in the platform registry');
    });

    it('rejects linking the same chat under a different kind in this workspace', function () {
        $workspace = entityLinkWorkspace('ws-kind-dup');
        $service = new EntityLinkService();

        $service->link($workspace->id, EntityKind::Channel, -100777, null, 1);

        expect(fn (): mixed => $service->link($workspace->id, EntityKind::ChatGroup, -100777, null, 1))
            ->toThrow(EntityException::class, 'already linked in this workspace');
    });

    it('rejects a chat already linked in another workspace (cross-workspace isolation)', function () {
        $workspaceA = entityLinkWorkspace('ws-x-a');
        $workspaceB = entityLinkWorkspace('ws-x-b');
        $service = new EntityLinkService();

        $service->link($workspaceA->id, EntityKind::Channel, -100888, null, 1);

        expect(fn (): mixed => $service->link($workspaceB->id, EntityKind::Channel, -100888, null, 2))
            ->toThrow(EntityException::class, 'already linked in another workspace')
            ->and(TgEntity::query()->where('workspace_id', $workspaceB->id)->count())->toBe(0);
    });
});

describe('EntityLinkService::unlink', function () {
    it('removes an entity belonging to the workspace', function () {
        $workspace = entityLinkWorkspace('ws-unlink');
        $service = new EntityLinkService();
        $entity = $service->link($workspace->id, EntityKind::Channel, -100222, null, 1);

        expect($service->unlink($workspace->id, $entity->id))->toBeTrue()
            ->and($service->unlink($workspace->id, $entity->id))->toBeFalse()
            ->and(TgEntity::query()->count())->toBe(0);
    });

    it('returns false for an unknown id', function () {
        $workspace = entityLinkWorkspace('ws-unlink-miss');

        expect((new EntityLinkService())->unlink($workspace->id, 424242))->toBeFalse();
    });

    it('cannot unlink an entity of another workspace (negative cross-workspace case)', function () {
        $workspaceA = entityLinkWorkspace('ws-un-x-a');
        $workspaceB = entityLinkWorkspace('ws-un-x-b');
        $service = new EntityLinkService();
        $entity = $service->link($workspaceA->id, EntityKind::Channel, -100333, null, 1);

        expect($service->unlink($workspaceB->id, $entity->id))->toBeFalse()
            ->and(DB::table('tg_entities')->where('id', $entity->id)->count())->toBe(1);
    });
});

describe('EntityLinkService::list', function () {
    it('returns only the requested workspace entities, optionally narrowed by kind', function () {
        $workspaceA = entityLinkWorkspace('ws-list-a');
        $workspaceB = entityLinkWorkspace('ws-list-b');
        $bot = TgBot::factory()->create();
        $service = new EntityLinkService();

        $service->link($workspaceA->id, EntityKind::Channel, -100444, null, 1, 'Channel');
        $service->link($workspaceA->id, EntityKind::Bot, null, $bot->bot_id, 1, 'Bot');
        $service->link($workspaceB->id, EntityKind::Channel, -100555, null, 2, 'Other workspace channel');

        expect($service->list($workspaceA->id))->toHaveCount(2)
            ->and($service->list($workspaceA->id, EntityKind::Channel))->toHaveCount(1)
            ->and($service->list($workspaceA->id, EntityKind::Channel)->first()->external_id)->toBe(-100444)
            ->and($service->list($workspaceA->id, EntityKind::Bot))->toHaveCount(1)
            // negative cross-workspace case: B must never see A's rows
            ->and($service->list($workspaceB->id))->toHaveCount(1)
            ->and($service->list($workspaceB->id)->first()->external_id)->toBe(-100555);
    });

    it('never leaks entities of another workspace even when the kind filter matches', function () {
        $workspaceA = entityLinkWorkspace('ws-leak-a');
        $workspaceB = entityLinkWorkspace('ws-leak-b');
        $service = new EntityLinkService();

        $service->link($workspaceA->id, EntityKind::Channel, -100666, null, 1);

        expect($service->list($workspaceB->id, EntityKind::Channel))->toBeEmpty();
    });
});
