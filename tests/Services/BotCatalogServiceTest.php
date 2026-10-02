<?php

declare(strict_types=1);

use BAGArt\TelegramBotManagement\Enums\BotAvailability;
use BAGArt\TelegramBotManagement\Enums\BotClass;
use BAGArt\TelegramBotManagement\Enums\EntityKind;
use BAGArt\TelegramBotManagement\Exceptions\BotCatalogException;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Models\TgEntity;
use BAGArt\TelegramBotManagement\Models\Workspace;
use BAGArt\TelegramBotManagement\Services\BotCatalogService;
use BAGArt\TelegramBotManagement\Services\EntityLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function botCatalogWorkspace(string $slug, ?array $settings = null): Workspace
{
    return Workspace::create(['name' => $slug, 'slug' => $slug, 'settings' => $settings]);
}

describe('BotCatalogService::addableBots', function () {
    it('returns public platform bots and hides private ones without the entitlement', function () {
        $workspace = botCatalogWorkspace('catalog-free');
        $public = TgBot::factory()->create();
        $private = TgBot::factory()->privateCatalog()->create();

        $bots = (new BotCatalogService(new EntityLinkService()))->addableBots($workspace->id);

        expect($bots->pluck('bot_id')->all())->toBe([$public->bot_id])
            ->and($bots->pluck('bot_id')->all())->not->toContain($private->bot_id);
    });

    it('includes private bots when the workspace holds the entitlement flag', function () {
        $workspace = botCatalogWorkspace('catalog-paid', ['entitlements' => ['private_bots']]);
        $public = TgBot::factory()->create();
        $private = TgBot::factory()->privateCatalog()->create();

        $bots = (new BotCatalogService(new EntityLinkService()))->addableBots($workspace->id);

        expect($bots->pluck('bot_id')->all())->toEqualCanonicalizing([$public->bot_id, $private->bot_id]);
    });

    it('excludes bots already linked into this workspace', function () {
        $workspace = botCatalogWorkspace('catalog-linked');
        $linked = TgBot::factory()->create();
        $free = TgBot::factory()->create();
        $service = new BotCatalogService(new EntityLinkService());

        (new EntityLinkService())->link($workspace->id, EntityKind::Bot, null, $linked->bot_id, 1);

        $bots = $service->addableBots($workspace->id);

        expect($bots->pluck('bot_id')->all())->toBe([$free->bot_id]);
    });

    it('excludes external bots from the catalog', function () {
        $workspace = botCatalogWorkspace('catalog-external');
        TgBot::factory()->external()->create();
        TgBot::factory()->create();

        $bots = (new BotCatalogService(new EntityLinkService()))->addableBots($workspace->id);

        expect($bots)->toHaveCount(1)
            ->and($bots->first()->class)->toBe(BotClass::Platform);
    });
});

describe('BotCatalogService::hasEntitlement', function () {
    it('reads the superadmin-managed flags from workspace settings', function () {
        $service = new BotCatalogService(new EntityLinkService());
        $entitled = botCatalogWorkspace('entitled', ['entitlements' => ['private_bots', 'other_flag']]);
        $plain = botCatalogWorkspace('plain');
        $emptyFlags = botCatalogWorkspace('empty-flags', ['entitlements' => []]);

        expect($service->hasEntitlement($entitled->id, 'private_bots'))->toBeTrue()
            ->and($service->hasEntitlement($entitled->id, 'missing_flag'))->toBeFalse()
            ->and($service->hasEntitlement($plain->id, 'private_bots'))->toBeFalse()
            ->and($service->hasEntitlement($emptyFlags->id, 'private_bots'))->toBeFalse()
            ->and($service->hasEntitlement(424242, 'private_bots'))->toBeFalse();
    });
});

describe('BotCatalogService::addLink', function () {
    it('builds the telegram add-to-group link', function () {
        $service = new BotCatalogService(new EntityLinkService());

        expect($service->addLink('MyBotBot'))->toBe('https://t.me/MyBotBot?startgroup=new')
            ->and($service->addLink('@MyBotBot'))->toBe('https://t.me/MyBotBot?startgroup=new');
    });
});

describe('BotCatalogService::connectExternal', function () {
    it('creates an external bot with an encrypted, hidden token and records the workspace link', function () {
        $workspace = botCatalogWorkspace('ext-connect');
        $service = new BotCatalogService(new EntityLinkService());
        $token = '555000111:'.str_repeat('T', 35);

        $bot = $service->connectExternal($workspace->id, '555000111', $token);

        expect($bot->class)->toBe(BotClass::External)
            ->and($bot->availability)->toBe(BotAvailability::Private)
            ->and($bot->external_token)->toBe($token)
            // raw storage is ciphertext — the plaintext token must not appear
            ->and($bot->getAttributes()['external_token'])->not->toBe($token)
            ->and($bot->getAttributes()['external_token'])->not->toContain($token)
            ->and($bot->toArray())->not->toHaveKey('external_token')
            ->and($bot->toArray())->not->toHaveKey('token');

        $fresh = TgBot::query()->find('555000111');
        expect($fresh->external_token)->toBe($token);

        $link = TgEntity::query()
            ->where('workspace_id', $workspace->id)
            ->where('bot_id', '555000111')
            ->first();
        expect($link)->not->toBeNull()
            ->and($link->kind)->toBe(EntityKind::Bot)
            ->and($link->linked_by)->toBeNull();
    });

    it('updates the token of an already connected external bot', function () {
        $workspace = botCatalogWorkspace('ext-update');
        $service = new BotCatalogService(new EntityLinkService());

        $service->connectExternal($workspace->id, '555000222', '555000222:'.str_repeat('A', 35));
        $updated = $service->connectExternal($workspace->id, '555000222', '555000222:'.str_repeat('B', 35));

        expect($updated->external_token)->toBe('555000222:'.str_repeat('B', 35))
            ->and(TgBot::query()->count())->toBe(1);
    });

    it('rejects platform bots', function () {
        $workspace = botCatalogWorkspace('ext-platform');
        $platform = TgBot::factory()->create();
        $service = new BotCatalogService(new EntityLinkService());

        expect(fn (): mixed => $service->connectExternal($workspace->id, $platform->bot_id, $platform->token))
            ->toThrow(BotCatalogException::class, 'is a platform bot');
    });

    it('rejects malformed or empty tokens without creating a row', function (string $token) {
        $workspace = botCatalogWorkspace('ext-bad-token');
        $service = new BotCatalogService(new EntityLinkService());

        expect(fn (): mixed => $service->connectExternal($workspace->id, '555000333', $token))
            ->toThrow(BotCatalogException::class, 'does not match the Telegram bot token format')
            ->and(TgBot::query()->find('555000333'))->toBeNull();
    })->with([
        'empty' => [''],
        'no separator' => ['555000333'.str_repeat('A', 35)],
        'short secret' => ['555000333:'.str_repeat('A', 10)],
        'non numeric id' => ['abc:'.str_repeat('A', 35)],
    ]);
});

describe('BotCatalogService::registryOnly', function () {
    it('records an external bot without a token and links it (registry-only invariant)', function () {
        $workspace = botCatalogWorkspace('reg-only');
        $service = new BotCatalogService(new EntityLinkService());

        $bot = $service->registryOnly($workspace->id, '777000333');

        expect($bot->class)->toBe(BotClass::External)
            ->and($bot->external_token)->toBeNull()
            ->and(TgBot::query()->find('777000333')->external_token)->toBeNull()
            ->and(TgEntity::query()->where('workspace_id', $workspace->id)->where('bot_id', '777000333')->exists())
            ->toBeTrue();
    });

    it('never downgrades an already connected bot to registry-only', function () {
        $workspace = botCatalogWorkspace('reg-keep');
        $service = new BotCatalogService(new EntityLinkService());
        $token = '777000444:'.str_repeat('C', 35);
        $service->connectExternal($workspace->id, '777000444', $token);

        $bot = $service->registryOnly($workspace->id, '777000444');

        expect($bot->external_token)->toBe($token);
    });

    it('rejects platform bots', function () {
        $workspace = botCatalogWorkspace('reg-platform');
        $platform = TgBot::factory()->create();
        $service = new BotCatalogService(new EntityLinkService());

        expect(fn (): mixed => $service->registryOnly($workspace->id, $platform->bot_id))
            ->toThrow(BotCatalogException::class, 'is a platform bot');
    });
});
