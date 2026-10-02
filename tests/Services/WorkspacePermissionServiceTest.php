<?php

declare(strict_types=1);

use BAGArt\TelegramBotAccess\Application\GrantAccess;
use BAGArt\TelegramBotAccess\Application\DenyAccess;
use BAGArt\TelegramBotAccess\Application\RevokeAccess;
use BAGArt\TelegramBotAccess\AccessControlContract;
use BAGArt\TelegramBotAccess\Permission\NonGrantableCapabilityException;
use BAGArt\TelegramBotAccess\Permission\PermissionCatalog;
use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotManagement\Models\Workspace;
use BAGArt\TelegramBotManagement\Services\WorkspacePermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function rbacPermissionService(): WorkspacePermissionService
{
    return new WorkspacePermissionService(
        grantAccess: app(GrantAccess::class),
        denyAccess: app(DenyAccess::class),
        revokeAccess: app(RevokeAccess::class),
        accessControl: app(AccessControlContract::class),
        catalog: app(PermissionCatalog::class),
    );
}

function rbacPermissionWorkspace(string $slug): Workspace
{
    return Workspace::query()->create(['name' => $slug, 'slug' => $slug]);
}

describe('WorkspacePermissionService::grantT2', function () {
    it('stores a workspace-scope grant row', function () {
        $workspace = rbacPermissionWorkspace('grant-ws');
        $subject = 4242;
        $actor = 1111;

        $grant = rbacPermissionService()->grantT2(
            workspaceId: $workspace->id,
            botId: 'bot-1',
            targetUserId: $subject,
            capability: 'game.initiate',
            actorUserId: $actor,
        );

        $row = DB::table('access_grants')->sole();

        expect($grant->scope->value)->toBe('workspace')
            ->and($row->scope)->toBe('workspace')
            ->and((int) $row->workspace_id)->toBe($workspace->id)
            ->and($row->chat_id)->toBeNull()
            ->and($row->bot_id)->toBe('bot-1')
            ->and($row->subject_id)->toBe((string) $subject)
            ->and($row->capability)->toBe('game.initiate')
            ->and($row->effect)->toBe('allow')
            ->and($row->granted_by)->toBe((string) $actor);
    });

    it('stores a chat-scope grant row with a NULL workspace', function () {
        $workspace = rbacPermissionWorkspace('grant-chat');
        $chatId = -100987654321;

        rbacPermissionService()->grantT2(
            workspaceId: $workspace->id,
            botId: 'bot-1',
            targetUserId: 4242,
            capability: 'moderation.reason.view',
            chatId: $chatId,
        );

        $row = DB::table('access_grants')->sole();

        expect($row->scope)->toBe('chat')
            ->and((int) $row->chat_id)->toBe($chatId)
            ->and($row->workspace_id)->toBeNull()
            ->and($row->granted_by)->toBe('system');
    });

    it('is an idempotent upsert for the same identity and effect', function () {
        $workspace = rbacPermissionWorkspace('grant-idem');
        $service = rbacPermissionService();

        $first = $service->grantT2($workspace->id, 'bot-1', 4242, 'game.initiate');
        $second = $service->grantT2($workspace->id, 'bot-1', 4242, 'game.initiate');

        expect($second->capability)->toBe($first->capability)
            ->and(DB::table('access_grants')->count())->toBe(1);
    });

    it('rejects T1 capabilities as non-grantable', function () {
        $workspace = rbacPermissionWorkspace('grant-t1');

        expect(fn () => rbacPermissionService()->grantT2($workspace->id, 'bot-1', 4242, 'ban'))
            ->toThrow(NonGrantableCapabilityException::class)
            ->and(DB::table('access_grants')->count())->toBe(0);
    });

    it('rejects the T0 membership baseline as a non-grant', function () {
        $workspace = rbacPermissionWorkspace('grant-t0');

        expect(fn () => rbacPermissionService()->grantT2($workspace->id, 'bot-1', 4242, 'menu.invoke'))
            ->toThrow(InvalidArgumentException::class, 'membership lives in workspace_members')
            ->and(DB::table('access_grants')->count())->toBe(0);
    });

    it('honours an expiry on the stored grant', function () {
        $workspace = rbacPermissionWorkspace('grant-ttl');
        $expiresAt = new DateTimeImmutable('+1 day');

        rbacPermissionService()->grantT2(
            workspaceId: $workspace->id,
            botId: 'bot-1',
            targetUserId: 4242,
            capability: 'game.initiate',
            expiresAt: $expiresAt,
        );

        $row = DB::table('access_grants')->sole();

        expect((string) $row->expires_at)
            ->toContain($expiresAt->format('Y-m-d H:i'));
    });
});

describe('WorkspacePermissionService::can', function () {
    it('allows a workspace-scope grant for a workspace request', function () {
        $workspace = rbacPermissionWorkspace('can-ws');
        $service = rbacPermissionService();
        $service->grantT2($workspace->id, 'bot-1', 4242, 'game.initiate');

        $decision = $service->can($workspace->id, 'bot-1', 4242, 'game.initiate');

        expect($decision->allowed)->toBeTrue()
            ->and($decision->reason)->toContain('workspace-scoped ALLOW');
    });

    it('denies by default when nothing is granted', function () {
        $workspace = rbacPermissionWorkspace('can-default');

        $decision = rbacPermissionService()->can($workspace->id, 'bot-1', 4242, 'game.initiate');

        expect($decision->allowed)->toBeFalse()
            ->and($decision->reason)->toContain('no active grant');
    });

    it('does not leak a grant from workspace A into workspace B', function () {
        $workspaceA = rbacPermissionWorkspace('iso-a');
        $workspaceB = rbacPermissionWorkspace('iso-b');
        $service = rbacPermissionService();
        $service->grantT2($workspaceA->id, 'bot-1', 4242, 'game.initiate');

        expect($service->can($workspaceA->id, 'bot-1', 4242, 'game.initiate')->allowed)->toBeTrue()
            ->and($service->can($workspaceB->id, 'bot-1', 4242, 'game.initiate')->allowed)->toBeFalse();
    });

    it('matches chat-scope grants only for that chat', function () {
        $workspace = rbacPermissionWorkspace('can-chat');
        $service = rbacPermissionService();
        $service->grantT2($workspace->id, 'bot-1', 4242, 'moderation.log.view', chatId: -1001);

        expect($service->can($workspace->id, 'bot-1', 4242, 'moderation.log.view', chatId: -1001)->allowed)->toBeTrue()
            ->and($service->can($workspace->id, 'bot-1', 4242, 'moderation.log.view', chatId: -1002)->allowed)->toBeFalse()
            ->and($service->can($workspace->id, 'bot-1', 4242, 'moderation.log.view')->allowed)->toBeFalse();
    });

    it('keeps subjects isolated', function () {
        $workspace = rbacPermissionWorkspace('can-subjects');
        $service = rbacPermissionService();
        $service->grantT2($workspace->id, 'bot-1', 4242, 'game.initiate');

        expect($service->can($workspace->id, 'bot-1', 4243, 'game.initiate')->allowed)->toBeFalse();
    });
});

describe('WorkspacePermissionService::denyT2', function () {
    it('narrows a workspace allow with a chat-scope deny', function () {
        $workspace = rbacPermissionWorkspace('deny-narrow');
        $service = rbacPermissionService();
        $service->grantT2($workspace->id, 'bot-1', 4242, 'game.initiate');
        $deny = $service->denyT2($workspace->id, 'bot-1', 4242, 'game.initiate', chatId: -1001);

        $row = DB::table('access_grants')
            ->where('scope', 'chat')
            ->sole();

        expect($deny->effect->value)->toBe('deny')
            ->and($row->effect)->toBe('deny')
            ->and($service->can($workspace->id, 'bot-1', 4242, 'game.initiate', chatId: -1001)->allowed)->toBeFalse()
            ->and($service->can($workspace->id, 'bot-1', 4242, 'game.initiate')->allowed)->toBeTrue();
    });

    it('rejects T1 and T0 capabilities like grantT2', function () {
        $workspace = rbacPermissionWorkspace('deny-tiers');

        expect(fn () => rbacPermissionService()->denyT2($workspace->id, 'bot-1', 4242, 'unban'))
            ->toThrow(NonGrantableCapabilityException::class)
            ->and(fn () => rbacPermissionService()->denyT2($workspace->id, 'bot-1', 4242, 'menu.invoke'))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('WorkspacePermissionService::revokeT2', function () {
    it('removes an existing grant and falls back to deny', function () {
        $workspace = rbacPermissionWorkspace('revoke');
        $service = rbacPermissionService();
        $service->grantT2($workspace->id, 'bot-1', 4242, 'game.initiate');

        $removed = $service->revokeT2($workspace->id, 'bot-1', 4242, 'game.initiate');

        expect($removed)->toBeTrue()
            ->and(DB::table('access_grants')->count())->toBe(0)
            ->and($service->can($workspace->id, 'bot-1', 4242, 'game.initiate')->allowed)->toBeFalse();
    });

    it('returns false when there is nothing to revoke', function () {
        $workspace = rbacPermissionWorkspace('revoke-missing');

        expect(rbacPermissionService()->revokeT2($workspace->id, 'bot-1', 4242, 'game.initiate'))->toBeFalse();
    });

    it('only revokes the matching scope', function () {
        $workspace = rbacPermissionWorkspace('revoke-scope');
        $service = rbacPermissionService();
        $service->grantT2($workspace->id, 'bot-1', 4242, 'game.initiate');

        $removedChat = $service->revokeT2($workspace->id, 'bot-1', 4242, 'game.initiate', chatId: -1001);

        expect($removedChat)->toBeFalse()
            ->and(DB::table('access_grants')->count())->toBe(1);
    });
});

describe('WorkspacePermissionService audit emission', function () {
    it('records grant changes through the access module event listener', function () {
        $sink = new class () implements AuditSinkContract {
            /** @var list<\BAGArt\TelegramBotAudit\AuditEntry> */
            public array $entries = [];

            public function append(\BAGArt\TelegramBotAudit\AuditEntry $entry): void
            {
                $this->entries[] = $entry;
            }
        };
        app()->instance(AuditSinkContract::class, $sink);

        $workspace = rbacPermissionWorkspace('audit-grant');
        $service = rbacPermissionService();
        $service->grantT2($workspace->id, 'bot-1', 4242, 'game.initiate', actorUserId: 7);
        $service->revokeT2($workspace->id, 'bot-1', 4242, 'game.initiate', actorUserId: 7);

        $operations = array_map(
            static fn ($entry): string => $entry->operationString(),
            $sink->entries,
        );

        expect($operations)->toBe(['access.grant.created', 'access.grant.revoked']);
    });
});
