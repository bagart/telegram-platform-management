<?php

declare(strict_types=1);

use BAGArt\TelegramBotManagement\Enums\EntityKind;
use BAGArt\TelegramBotManagement\Enums\WorkspaceMemberStatus;
use BAGArt\TelegramBotManagement\Enums\WorkspaceRole;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Models\TgBotOwner;
use BAGArt\TelegramBotManagement\Models\TgEntity;
use BAGArt\TelegramBotManagement\Models\Workspace;
use BAGArt\TelegramBotManagement\Models\WorkspaceMember;
use BAGArt\TelegramBotManagement\Services\BotOwnershipResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ownershipResolverBot(string $botId): TgBot
{
    return TgBot::create(['bot_id' => $botId, 'token' => "t:{$botId}"]);
}

function ownershipResolverWorkspace(string $slug): Workspace
{
    return Workspace::create(['name' => $slug, 'slug' => $slug]);
}

function ownershipResolverMember(
    Workspace $workspace,
    int $userId,
    WorkspaceRole $role = WorkspaceRole::Owner,
    WorkspaceMemberStatus $status = WorkspaceMemberStatus::Active,
): void {
    WorkspaceMember::create([
        'workspace_id' => $workspace->id,
        'user_id' => $userId,
        'role' => $role,
        'status' => $status,
    ]);
}

function ownershipResolverLinkBot(Workspace $workspace, string $botId): void
{
    TgEntity::create([
        'workspace_id' => $workspace->id,
        'kind' => EntityKind::Bot,
        'bot_id' => $botId,
        'linked_at' => now(),
    ]);
}

function ownershipResolverLinkChannel(Workspace $workspace, int $externalId): void
{
    TgEntity::create([
        'workspace_id' => $workspace->id,
        'kind' => EntityKind::Channel,
        'external_id' => $externalId,
        'linked_at' => now(),
    ]);
}

function ownershipResolverUser(): int
{
    return \App\Models\User::create([
        'name' => 'Owner User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
    ])->id;
}

describe('BotOwnershipResolver::isOwner', function () {
    it('is true for an active owner of a workspace linking the bot', function () {
        $userId = ownershipResolverUser();
        ownershipResolverBot('bot1');
        $workspace = ownershipResolverWorkspace('resolver-owner');
        ownershipResolverMember($workspace, $userId);
        ownershipResolverLinkBot($workspace, 'bot1');

        expect((new BotOwnershipResolver())->isOwner($userId, 'bot1'))->toBeTrue();
    });

    it('is false for a non-owner member of the linking workspace', function () {
        $userId = ownershipResolverUser();
        ownershipResolverBot('bot1');
        $workspace = ownershipResolverWorkspace('resolver-member');
        ownershipResolverMember($workspace, $userId, WorkspaceRole::Member);
        ownershipResolverLinkBot($workspace, 'bot1');

        expect((new BotOwnershipResolver())->isOwner($userId, 'bot1'))->toBeFalse();
    });

    it('is false for an owner of a workspace that does not link the bot', function () {
        $userId = ownershipResolverUser();
        ownershipResolverBot('bot1');
        ownershipResolverBot('bot2');
        $workspace = ownershipResolverWorkspace('resolver-other');
        ownershipResolverMember($workspace, $userId);
        ownershipResolverLinkBot($workspace, 'bot2');

        expect((new BotOwnershipResolver())->isOwner($userId, 'bot1'))->toBeFalse()
            ->and((new BotOwnershipResolver())->isOwner($userId, 'bot2'))->toBeTrue();
    });

    it('is false for a suspended owner of the linking workspace', function () {
        $userId = ownershipResolverUser();
        ownershipResolverBot('bot1');
        $workspace = ownershipResolverWorkspace('resolver-suspended');
        ownershipResolverMember($workspace, $userId, WorkspaceRole::Owner, WorkspaceMemberStatus::Suspended);
        ownershipResolverLinkBot($workspace, 'bot1');

        expect((new BotOwnershipResolver())->isOwner($userId, 'bot1'))->toBeFalse();
    });

    it('is true through a second workspace (union across workspaces)', function () {
        $userId = ownershipResolverUser();
        ownershipResolverBot('bot1');
        $empty = ownershipResolverWorkspace('resolver-empty');
        ownershipResolverMember($empty, $userId);
        $linking = ownershipResolverWorkspace('resolver-second');
        ownershipResolverMember($linking, $userId);
        ownershipResolverLinkBot($linking, 'bot1');

        expect((new BotOwnershipResolver())->isOwner($userId, 'bot1'))->toBeTrue();
    });

    it('is false for a user without membership', function () {
        $userId = ownershipResolverUser();
        $other = ownershipResolverUser();
        ownershipResolverBot('bot1');
        $workspace = ownershipResolverWorkspace('resolver-outsider');
        ownershipResolverMember($workspace, $other);
        ownershipResolverLinkBot($workspace, 'bot1');

        expect((new BotOwnershipResolver())->isOwner($userId, 'bot1'))->toBeFalse();
    });

    it('ignores legacy tg_bot_owners rows as a rights source (D7)', function () {
        $userId = ownershipResolverUser();
        ownershipResolverBot('bot1');
        TgBotOwner::create(['bot_id' => 'bot1', 'user_id' => $userId]);

        expect((new BotOwnershipResolver())->isOwner($userId, 'bot1'))->toBeFalse();
    });
});

describe('BotOwnershipResolver::ownedBotIds', function () {
    it('returns every bot linked from owned workspaces, ordered by bot_id', function () {
        $userId = ownershipResolverUser();
        ownershipResolverBot('bot2');
        ownershipResolverBot('bot1');
        $workspace = ownershipResolverWorkspace('resolver-multi');
        ownershipResolverMember($workspace, $userId);
        ownershipResolverLinkBot($workspace, 'bot2');
        ownershipResolverLinkBot($workspace, 'bot1');

        expect((new BotOwnershipResolver())->ownedBotIds($userId))->toBe(['bot1', 'bot2']);
    });

    it('unions bots across several owned workspaces', function () {
        $userId = ownershipResolverUser();
        ownershipResolverBot('bot1');
        ownershipResolverBot('bot2');
        $first = ownershipResolverWorkspace('resolver-union-a');
        ownershipResolverMember($first, $userId);
        ownershipResolverLinkBot($first, 'bot1');
        $second = ownershipResolverWorkspace('resolver-union-b');
        ownershipResolverMember($second, $userId);
        ownershipResolverLinkBot($second, 'bot2');

        expect((new BotOwnershipResolver())->ownedBotIds($userId))->toBe(['bot1', 'bot2']);
    });

    it('dedupes a bot linked from two owned workspaces', function () {
        $userId = ownershipResolverUser();
        ownershipResolverBot('bot1');
        $first = ownershipResolverWorkspace('resolver-dedupe-a');
        ownershipResolverMember($first, $userId);
        ownershipResolverLinkBot($first, 'bot1');
        $second = ownershipResolverWorkspace('resolver-dedupe-b');
        ownershipResolverMember($second, $userId);
        ownershipResolverLinkBot($second, 'bot1');

        expect((new BotOwnershipResolver())->ownedBotIds($userId))->toBe(['bot1']);
    });

    it('excludes bots of workspaces where the user is not an active owner', function () {
        $userId = ownershipResolverUser();
        ownershipResolverBot('bot1');
        ownershipResolverBot('bot2');
        ownershipResolverBot('bot3');
        $member = ownershipResolverWorkspace('resolver-member-ws');
        ownershipResolverMember($member, $userId, WorkspaceRole::Member);
        ownershipResolverLinkBot($member, 'bot1');
        $suspended = ownershipResolverWorkspace('resolver-suspended-ws');
        ownershipResolverMember($suspended, $userId, WorkspaceRole::Owner, WorkspaceMemberStatus::Suspended);
        ownershipResolverLinkBot($suspended, 'bot2');
        $owned = ownershipResolverWorkspace('resolver-owned-ws');
        ownershipResolverMember($owned, $userId);
        ownershipResolverLinkBot($owned, 'bot3');

        expect((new BotOwnershipResolver())->ownedBotIds($userId))->toBe(['bot3']);
    });

    it('excludes channel entities', function () {
        $userId = ownershipResolverUser();
        ownershipResolverBot('bot1');
        $workspace = ownershipResolverWorkspace('resolver-channel');
        ownershipResolverMember($workspace, $userId);
        ownershipResolverLinkChannel($workspace, -100123);
        ownershipResolverLinkBot($workspace, 'bot1');

        expect((new BotOwnershipResolver())->ownedBotIds($userId))->toBe(['bot1'])
            ->and(TgEntity::query()->count())->toBe(2);
    });

    it('is empty when the user owns nothing', function () {
        $userId = ownershipResolverUser();
        ownershipResolverBot('bot1');

        expect((new BotOwnershipResolver())->ownedBotIds($userId))->toBe([]);
    });
});

describe('BotOwnershipResolver::ownerUserIds', function () {
    it('returns the union of active owners across workspaces linking the bot', function () {
        $first = ownershipResolverUser();
        $second = ownershipResolverUser();
        $member = ownershipResolverUser();
        ownershipResolverBot('bot1');
        $workspaceA = ownershipResolverWorkspace('resolver-inverse-a');
        ownershipResolverMember($workspaceA, $first);
        ownershipResolverLinkBot($workspaceA, 'bot1');
        $workspaceB = ownershipResolverWorkspace('resolver-inverse-b');
        ownershipResolverMember($workspaceB, $second);
        ownershipResolverMember($workspaceB, $member, WorkspaceRole::Member);
        ownershipResolverLinkBot($workspaceB, 'bot1');

        expect((new BotOwnershipResolver())->ownerUserIds('bot1'))->toBe([$first, $second]);
    });

    it('excludes suspended owners and owners of unrelated workspaces', function () {
        $active = ownershipResolverUser();
        $suspended = ownershipResolverUser();
        $unrelated = ownershipResolverUser();
        ownershipResolverBot('bot1');
        $workspace = ownershipResolverWorkspace('resolver-inverse-active');
        ownershipResolverMember($workspace, $active);
        ownershipResolverMember($workspace, $suspended, WorkspaceRole::Owner, WorkspaceMemberStatus::Suspended);
        ownershipResolverLinkBot($workspace, 'bot1');
        $other = ownershipResolverWorkspace('resolver-inverse-unrelated');
        ownershipResolverMember($other, $unrelated);

        expect((new BotOwnershipResolver())->ownerUserIds('bot1'))->toBe([$active]);
    });

    it('is empty for an unknown bot', function () {
        expect((new BotOwnershipResolver())->ownerUserIds('999999'))->toBe([]);
    });
});
