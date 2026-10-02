<?php

declare(strict_types=1);

use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotManagement\Enums\WorkspaceMemberStatus;
use BAGArt\TelegramBotManagement\Enums\WorkspaceRole;
use BAGArt\TelegramBotManagement\Exceptions\WorkspaceAdminException;
use BAGArt\TelegramBotManagement\Models\WorkspaceMember;
use BAGArt\TelegramBotManagement\Services\WorkspaceAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function rbacUser(string $name = 'rbac-user', ?int $telegramId = null): int
{
    return (int) DB::table('users')->insertGetId([
        'name' => $name,
        'email' => null,
        'password' => null,
        'telegram_id' => $telegramId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function rbacAdminService(): WorkspaceAdminService
{
    return app(WorkspaceAdminService::class);
}

/**
 * Spy over the audit sink: records every appended entry for assertions.
 */
final class RbacSpyAuditSink implements AuditSinkContract
{
    /** @var list<AuditEntry> */
    public array $entries = [];

    public function append(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /** @return list<string> */
    public function operations(): array
    {
        return array_map(static fn (AuditEntry $entry): string => $entry->operationString(), $this->entries);
    }
}

describe('WorkspaceAdminService::createWorkspace', function () {
    it('creates a workspace with the creator as active Owner', function () {
        $creator = rbacUser('Creator');

        $workspace = rbacAdminService()->createWorkspace($creator, 'My Team');

        expect($workspace->slug)->toBe('my-team')
            ->and($workspace->name)->toBe('My Team');

        $member = WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $creator)
            ->sole();

        expect($member->role)->toBe(WorkspaceRole::Owner)
            ->and($member->status)->toBe(WorkspaceMemberStatus::Active)
            ->and($member->joined_at)->not->toBeNull();
    });

    it('generates unique deterministic slugs for duplicate names', function () {
        $creator = rbacUser();
        $service = rbacAdminService();

        $first = $service->createWorkspace($creator, 'My Team');
        $second = $service->createWorkspace($creator, 'My Team');
        $third = $service->createWorkspace($creator, 'My Team');

        expect($first->slug)->toBe('my-team')
            ->and($second->slug)->toBe('my-team-2')
            ->and($third->slug)->toBe('my-team-3');
    });

    it('falls back to the workspace base slug for a name without slug chars', function () {
        $creator = rbacUser();

        $workspace = rbacAdminService()->createWorkspace($creator, '!!!');

        expect($workspace->slug)->toBe('workspace');
    });

    it('rejects blank and oversized names', function () {
        $creator = rbacUser();

        expect(fn () => rbacAdminService()->createWorkspace($creator, '   '))
            ->toThrow(WorkspaceAdminException::class, 'Workspace name must be 1-120 characters')
            ->and(fn () => rbacAdminService()->createWorkspace($creator, str_repeat('a', 121)))
            ->toThrow(WorkspaceAdminException::class, 'Workspace name must be 1-120 characters');
    });

    it('rejects an unknown creator', function () {
        expect(fn () => rbacAdminService()->createWorkspace(999999, 'Orphan'))
            ->toThrow(WorkspaceAdminException::class, 'User [999999] does not exist.');
    });
});

describe('WorkspaceAdminService::listFor', function () {
    it('returns only workspaces where the user holds an active membership', function () {
        $alice = rbacUser('Alice');
        $bob = rbacUser('Bob');
        $service = rbacAdminService();

        $aliceWorkspace = $service->createWorkspace($alice, 'Alice Co');
        $service->createWorkspace($bob, 'Bob Co');

        $listed = $service->listFor($alice);

        expect($listed->pluck('id')->all())->toBe([$aliceWorkspace->id])
            ->and($listed->first()->slug)->toBe('alice-co');
    });

    it('excludes workspaces where the membership is suspended', function () {
        $owner = rbacUser('Owner');
        $member = rbacUser('Member');
        $service = rbacAdminService();

        $workspace = $service->createWorkspace($owner, 'Suspension Co');
        $service->addMember($workspace->id, $owner, $member);

        DB::table('workspace_members')
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $member)
            ->update(['status' => WorkspaceMemberStatus::Suspended->value]);

        expect($service->listFor($member)->all())->toBeEmpty()
            ->and($service->isMember($workspace->id, $member))->toBeFalse();
    });
});

describe('WorkspaceAdminService::isMember', function () {
    it('reports active membership only', function () {
        $owner = rbacUser('Owner');
        $member = rbacUser('Member');
        $stranger = rbacUser('Stranger');
        $service = rbacAdminService();

        $workspace = $service->createWorkspace($owner, 'Presence Co');
        $service->addMember($workspace->id, $owner, $member);

        expect($service->isMember($workspace->id, $owner))->toBeTrue()
            ->and($service->isMember($workspace->id, $member))->toBeTrue()
            ->and($service->isMember($workspace->id, $stranger))->toBeFalse()
            ->and($service->isMember(999999, $owner))->toBeFalse();
    });
});

describe('WorkspaceAdminService::members', function () {
    it('lists members joined with their user info', function () {
        $owner = rbacUser('Owner Name', 111222333);
        $member = rbacUser('Member Name');
        $service = rbacAdminService();

        $workspace = $service->createWorkspace($owner, 'Roster Co');
        $service->addMember($workspace->id, $owner, $member);

        $rows = $service->members($workspace->id, $owner);

        expect($rows)->toHaveCount(2)
            ->and($rows[0])->toMatchArray([
                'user_id' => $owner,
                'name' => 'Owner Name',
                'telegram_id' => 111222333,
                'role' => WorkspaceRole::Owner,
                'status' => WorkspaceMemberStatus::Active,
            ])
            ->and($rows[1])->toMatchArray([
                'user_id' => $member,
                'name' => 'Member Name',
                'telegram_id' => null,
                'role' => WorkspaceRole::Member,
            ]);
    });

    it('lets a non-owner member list the roster', function () {
        $owner = rbacUser('Owner');
        $member = rbacUser('Member');
        $service = rbacAdminService();

        $workspace = $service->createWorkspace($owner, 'Open Co');
        $service->addMember($workspace->id, $owner, $member);

        expect($service->members($workspace->id, $member))->toHaveCount(2);
    });

    it('rejects a non-member actor and an unknown workspace', function () {
        $owner = rbacUser('Owner');
        $stranger = rbacUser('Stranger');
        $service = rbacAdminService();

        $workspace = $service->createWorkspace($owner, 'Closed Co');

        expect(fn () => $service->members($workspace->id, $stranger))
            ->toThrow(WorkspaceAdminException::class, 'is not an active member')
            ->and(fn () => $service->members(999999, $owner))
            ->toThrow(WorkspaceAdminException::class, 'does not exist');
    });
});

describe('WorkspaceAdminService::addMember', function () {
    it('adds a target user as an active Member when the actor is Owner', function () {
        $owner = rbacUser('Owner');
        $member = rbacUser('Member');
        $service = rbacAdminService();

        $workspace = $service->createWorkspace($owner, 'Add Co');
        $row = $service->addMember($workspace->id, $owner, $member);

        expect($row->role)->toBe(WorkspaceRole::Member)
            ->and($row->status)->toBe(WorkspaceMemberStatus::Active)
            ->and($service->isMember($workspace->id, $member))->toBeTrue();
    });

    it('is idempotent: an existing membership row is returned untouched', function () {
        $owner = rbacUser('Owner');
        $member = rbacUser('Member');
        $service = rbacAdminService();

        $workspace = $service->createWorkspace($owner, 'Idem Co');
        $first = $service->addMember($workspace->id, $owner, $member);
        $second = $service->addMember($workspace->id, $owner, $member, WorkspaceRole::Owner);

        expect($second->id)->toBe($first->id)
            ->and($second->role)->toBe(WorkspaceRole::Member)
            ->and(WorkspaceMember::query()->where('workspace_id', $workspace->id)->count())->toBe(2);
    });

    it('rejects a non-Owner member actor and a non-member actor', function () {
        $owner = rbacUser('Owner');
        $member = rbacUser('Member');
        $stranger = rbacUser('Stranger');
        $service = rbacAdminService();

        $workspace = $service->createWorkspace($owner, 'Guarded Co');
        $service->addMember($workspace->id, $owner, $member);

        expect(fn () => $service->addMember($workspace->id, $member, $stranger))
            ->toThrow(WorkspaceAdminException::class, 'is not an Owner')
            ->and(fn () => $service->addMember($workspace->id, $stranger, $member))
            ->toThrow(WorkspaceAdminException::class, 'is not an Owner');
    });

    it('rejects an unknown target user and an unknown workspace', function () {
        $owner = rbacUser('Owner');
        $service = rbacAdminService();

        $workspace = $service->createWorkspace($owner, 'Missing Co');

        expect(fn () => $service->addMember($workspace->id, $owner, 999999))
            ->toThrow(WorkspaceAdminException::class, 'User [999999] does not exist.')
            ->and(fn () => $service->addMember(999999, $owner, $owner))
            ->toThrow(WorkspaceAdminException::class, 'Workspace [999999] does not exist.');
    });
});

describe('WorkspaceAdminService::changeRole', function () {
    it('promotes a member to Owner when the actor is Owner', function () {
        $owner = rbacUser('Owner');
        $member = rbacUser('Member');
        $service = rbacAdminService();

        $workspace = $service->createWorkspace($owner, 'Promote Co');
        $service->addMember($workspace->id, $owner, $member);

        $row = $service->changeRole($workspace->id, $owner, $member, WorkspaceRole::Owner);

        expect($row->role)->toBe(WorkspaceRole::Owner);
    });

    it('protects the last Owner from demotion', function () {
        $owner = rbacUser('Owner');
        $service = rbacAdminService();

        $workspace = $service->createWorkspace($owner, 'LastOwner Co');

        expect(fn () => $service->changeRole($workspace->id, $owner, $owner, WorkspaceRole::Member))
            ->toThrow(WorkspaceAdminException::class, 'must keep at least one Owner');

        expect(WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $owner)
            ->sole()->role)->toBe(WorkspaceRole::Owner);
    });

    it('allows demotion once a second Owner exists', function () {
        $owner = rbacUser('Owner');
        $second = rbacUser('Second');
        $service = rbacAdminService();

        $workspace = $service->createWorkspace($owner, 'TwoOwners Co');
        $service->addMember($workspace->id, $owner, $second);
        $service->changeRole($workspace->id, $owner, $second, WorkspaceRole::Owner);

        $row = $service->changeRole($workspace->id, $second, $owner, WorkspaceRole::Member);

        expect($row->role)->toBe(WorkspaceRole::Member)
            ->and(
                WorkspaceMember::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('role', WorkspaceRole::Owner->value)
                    ->count(),
            )->toBe(1);
    });

    it('is a no-op when the role is unchanged', function () {
        $owner = rbacUser('Owner');
        $member = rbacUser('Member');
        $service = rbacAdminService();

        $workspace = $service->createWorkspace($owner, 'Noop Co');
        $service->addMember($workspace->id, $owner, $member);

        $row = $service->changeRole($workspace->id, $owner, $member, WorkspaceRole::Member);

        expect($row->role)->toBe(WorkspaceRole::Member);
    });

    it('rejects a non-Owner actor and a target without membership', function () {
        $owner = rbacUser('Owner');
        $member = rbacUser('Member');
        $stranger = rbacUser('Stranger');
        $service = rbacAdminService();

        $workspace = $service->createWorkspace($owner, 'RoleGuard Co');
        $service->addMember($workspace->id, $owner, $member);

        expect(fn () => $service->changeRole($workspace->id, $member, $member, WorkspaceRole::Owner))
            ->toThrow(WorkspaceAdminException::class, 'is not an Owner')
            ->and(fn () => $service->changeRole($workspace->id, $owner, $stranger, WorkspaceRole::Member))
            ->toThrow(WorkspaceAdminException::class, 'holds no membership');
    });
});

describe('WorkspaceAdminService::removeMember', function () {
    it('deletes the membership row when the actor is Owner', function () {
        $owner = rbacUser('Owner');
        $member = rbacUser('Member');
        $service = rbacAdminService();

        $workspace = $service->createWorkspace($owner, 'Remove Co');
        $service->addMember($workspace->id, $owner, $member);

        $service->removeMember($workspace->id, $owner, $member);

        expect(WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $member)
            ->exists())->toBeFalse()
            ->and($service->isMember($workspace->id, $member))->toBeFalse();
    });

    it('protects the last Owner from removal', function () {
        $owner = rbacUser('Owner');
        $service = rbacAdminService();

        $workspace = $service->createWorkspace($owner, 'StickyOwner Co');

        expect(fn () => $service->removeMember($workspace->id, $owner, $owner))
            ->toThrow(WorkspaceAdminException::class, 'must keep at least one Owner')
            ->and($service->isMember($workspace->id, $owner))->toBeTrue();
    });

    it('rejects a non-Owner actor and a target without membership', function () {
        $owner = rbacUser('Owner');
        $member = rbacUser('Member');
        $stranger = rbacUser('Stranger');
        $service = rbacAdminService();

        $workspace = $service->createWorkspace($owner, 'RemoveGuard Co');
        $service->addMember($workspace->id, $owner, $member);

        expect(fn () => $service->removeMember($workspace->id, $member, $owner))
            ->toThrow(WorkspaceAdminException::class, 'is not an Owner')
            ->and(fn () => $service->removeMember($workspace->id, $owner, $stranger))
            ->toThrow(WorkspaceAdminException::class, 'holds no membership');
    });
});

describe('WorkspaceAdminService audit emission', function () {
    it('writes one audit entry per membership mutation', function () {
        $sink = new RbacSpyAuditSink();
        app()->instance(AuditSinkContract::class, $sink);

        $owner = rbacUser('Owner');
        $member = rbacUser('Member');
        $service = rbacAdminService();

        $workspace = $service->createWorkspace($owner, 'Audited Co');
        $service->addMember($workspace->id, $owner, $member);
        $service->changeRole($workspace->id, $owner, $member, WorkspaceRole::Owner);
        $service->removeMember($workspace->id, $owner, $member);

        expect($sink->operations())->toBe([
            'workspace.created',
            'workspace.member.added',
            'workspace.member.role.changed',
            'workspace.member.removed',
        ]);

        $added = $sink->entries[1];
        expect($added->target->subjectType)->toBe('workspace_member')
            ->and($added->target->subjectId)->toBe("{$workspace->id}:{$member}")
            ->and($added->actor->id)->toBe((string) $owner)
            ->and($added->source)->toBe('management')
            ->and($added->newState)->toBe(['role' => 'member', 'status' => 'active'])
            ->and($added->metadata)->toMatchArray([
                'workspace_id' => $workspace->id,
                'user_id' => $member,
            ]);

        $created = $sink->entries[0];
        expect($created->target->subjectType)->toBe('workspace')
            ->and($created->target->subjectId)->toBe((string) $workspace->id)
            ->and($created->newState)->toBe(['slug' => 'audited-co', 'owner_user_id' => $owner]);

        $roleChanged = $sink->entries[2];
        expect($roleChanged->oldState)->toBe(['role' => 'member'])
            ->and($roleChanged->newState)->toBe(['role' => 'owner']);
    });

    it('skips failed membership mutations from the audit trail', function () {
        $sink = new RbacSpyAuditSink();
        app()->instance(AuditSinkContract::class, $sink);

        $owner = rbacUser('Owner');
        $service = rbacAdminService();

        $workspace = $service->createWorkspace($owner, 'Quiet Co');

        expect(fn () => $service->addMember($workspace->id, $owner, 999999))
            ->toThrow(WorkspaceAdminException::class)
            ->and($sink->operations())->toBe(['workspace.created']);
    });
});
