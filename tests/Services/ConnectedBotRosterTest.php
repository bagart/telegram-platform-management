<?php

declare(strict_types=1);

use BAGArt\TelegramBotManagement\Enums\EntityKind;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Models\Workspace;
use BAGArt\TelegramBotManagement\Services\ConnectedBotRoster;
use BAGArt\TelegramBotManagement\Services\EntityLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function rosterWorkspace(string $slug): Workspace
{
    return Workspace::create(['name' => $slug, 'slug' => $slug]);
}

describe('ConnectedBotRoster', function () {
    it('returns only bots linked into the workspace', function () {
        $workspaceA = rosterWorkspace('roster-a');
        $workspaceB = rosterWorkspace('roster-b');
        $linkedHere = TgBot::factory()->create();
        $linkedThere = TgBot::factory()->create();
        $notLinked = TgBot::factory()->create();

        $service = new ConnectedBotRoster(new EntityLinkService());
        $service->linkCatalogBot($workspaceA->id, $linkedHere->bot_id, 1, 'Linked here');
        $service->linkCatalogBot($workspaceB->id, $linkedThere->bot_id, 2);

        $bots = $service->botsForWorkspace($workspaceA->id);

        expect($bots->pluck('bot_id')->all())->toBe([$linkedHere->bot_id])
            ->and($bots->pluck('bot_id')->all())->not->toContain($linkedThere->bot_id, $notLinked->bot_id);
    });

    it('returns an empty collection when nothing is linked', function () {
        $workspace = rosterWorkspace('roster-empty');

        $bots = (new ConnectedBotRoster(new EntityLinkService()))->botsForWorkspace($workspace->id);

        expect($bots)->toBeEmpty();
    });

    it('delegates linkCatalogBot to EntityLinkService without duplicating link logic', function () {
        $workspace = rosterWorkspace('roster-link');
        $bot = TgBot::factory()->create();
        $service = new ConnectedBotRoster(new EntityLinkService());

        $entity = $service->linkCatalogBot($workspace->id, $bot->bot_id, 5, 'Catalog bot', 'catalog_bot');

        expect($entity->kind)->toBe(EntityKind::Bot)
            ->and($entity->bot_id)->toBe($bot->bot_id)
            ->and($entity->title)->toBe('Catalog bot')
            ->and($entity->username)->toBe('catalog_bot')
            ->and($entity->linked_by)->toBe(5);

        // idempotent re-link through the roster returns the same row
        $again = $service->linkCatalogBot($workspace->id, $bot->bot_id, 5, 'Catalog bot');
        expect($again->id)->toBe($entity->id);
    });
});
