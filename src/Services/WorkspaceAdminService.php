<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Services;

use BAGArt\TelegramBotManagement\Enums\WorkspaceMemberStatus;
use BAGArt\TelegramBotManagement\Enums\WorkspaceRole;
use BAGArt\TelegramBotManagement\Exceptions\WorkspaceAdminException;
use BAGArt\TelegramBotManagement\Models\Workspace;
use BAGArt\TelegramBotManagement\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as ModelCollection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Workspace membership administration (D1/D8/D10, ADR-001): creation,
 * listing, membership roles and the Owner-managed membership lifecycle.
 *
 * Membership (T0) is a workspace_members fact, NOT an access grant — this
 * service never writes to access_grants. Permission checks combine
 * {@see self::isMember()} (T0 presence) with
 * WorkspacePermissionService::can() (T1/T2 grants); decide() itself has no
 * membership rung yet (see WorkspacePermissionService PHPDoc).
 *
 * Owner is the membership authority (ADR-001 "granters"): only an active
 * Owner adds/removes members and changes roles, and the last Owner of a
 * workspace can be neither demoted nor removed.
 *
 * Removal semantics: removeMember() DELETES the row — suspension as a
 * separate lifecycle state is deferred to a later wave, so history lives in
 * the audit trail instead of a kept-around row.
 */
final class WorkspaceAdminService
{
    /** Maximum slug length allowed by the workspaces.slug column is 140. */
    private const int MAX_NAME_LENGTH = 120;

    /** Bounded candidate loop; also absorbs lost unique-index races. */
    private const int MAX_SLUG_ATTEMPTS = 1000;

    public function __construct(
        private readonly TelegramIdentityService $identity,
        private readonly WorkspaceAuditRecorder $audit,
    ) {
    }

    /**
     * Self-serve workspace creation (D10): any authenticated user may create
     * any number of workspaces and becomes its Owner.
     *
     * The slug is deterministic (derived from the name) and collision-safe
     * (numeric suffix loop + retry on the unique-index race).
     *
     * @throws WorkspaceAdminException for a blank/oversized name, an unknown
     *                                 creator, or an exhausted slug loop
     */
    public function createWorkspace(int $creatorUserId, string $name): Workspace
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw WorkspaceAdminException::invalidName($name);
        }

        if (! $this->identity->userExists($creatorUserId)) {
            throw WorkspaceAdminException::userNotFound($creatorUserId);
        }

        $workspace = $this->createWithUniqueSlug($name);

        WorkspaceMember::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $creatorUserId,
            'role' => WorkspaceRole::Owner,
            'status' => WorkspaceMemberStatus::Active,
            'joined_at' => now(),
        ]);

        $this->audit->workspaceCreated($workspace->id, $workspace->slug, $creatorUserId);

        return $workspace;
    }

    /**
     * Workspaces where the user holds an ACTIVE membership — never
     * unscoped: suspended memberships disappear from the list.
     *
     * @return ModelCollection<int, Workspace>
     */
    public function listFor(int $userId): ModelCollection
    {
        $workspaceIds = WorkspaceMember::query()
            ->where('user_id', $userId)
            ->where('status', WorkspaceMemberStatus::Active->value)
            ->pluck('workspace_id');

        return Workspace::query()
            ->whereIn('id', $workspaceIds)
            ->orderBy('id')
            ->get();
    }

    /**
     * T0 presence check: active membership row for the user in the workspace.
     * Surfaces combine this with WorkspacePermissionService::can().
     */
    public function isMember(int $workspaceId, int $userId): bool
    {
        return $this->activeMemberQuery($workspaceId, $userId)->exists();
    }

    /**
     * Members of a workspace with their user info (ADR-001 member views).
     * Requires an ACTIVE membership for the actor; the listing itself covers
     * every membership row (suspended included — Owners must see them).
     *
     * @return SupportCollection<int, array{user_id: int, name: string, telegram_id: int|null, role: WorkspaceRole, status: WorkspaceMemberStatus, joined_at: string|null}>
     *
     * @throws WorkspaceAdminException when the workspace is unknown or the actor is not an active member
     */
    public function members(int $workspaceId, int $actorUserId): SupportCollection
    {
        $this->assertWorkspaceExists($workspaceId);
        $this->assertActiveMember($workspaceId, $actorUserId);

        $rows = DB::table('workspace_members')
            ->join('users', 'users.id', '=', 'workspace_members.user_id')
            ->where('workspace_members.workspace_id', $workspaceId)
            ->orderBy('workspace_members.id')
            ->select([
                'workspace_members.user_id',
                'workspace_members.role',
                'workspace_members.status',
                'workspace_members.joined_at',
                'users.name',
                'users.telegram_id',
            ])
            ->get();

        return $rows->map(static fn (object $row): array => [
            'user_id' => (int) $row->user_id,
            'name' => (string) $row->name,
            'telegram_id' => $row->telegram_id === null ? null : (int) $row->telegram_id,
            'role' => WorkspaceRole::from((string) $row->role),
            'status' => WorkspaceMemberStatus::from((string) $row->status),
            'joined_at' => $row->joined_at === null ? null : (string) $row->joined_at,
        ]);
    }

    /**
     * Add a user to the workspace (Owner-only per ADR-001 granters).
     * Idempotent: an existing membership row is returned untouched.
     *
     * @throws WorkspaceAdminException for an unknown workspace, a non-Owner
     *                                 actor, or an unknown target user
     */
    public function addMember(
        int $workspaceId,
        int $actorUserId,
        int $targetUserId,
        WorkspaceRole $role = WorkspaceRole::Member,
    ): WorkspaceMember {
        $this->assertWorkspaceExists($workspaceId);
        $this->assertOwner($workspaceId, $actorUserId);

        $existing = $this->memberRow($workspaceId, $targetUserId);
        if ($existing !== null) {
            return $existing;
        }

        if (! $this->identity->userExists($targetUserId)) {
            throw WorkspaceAdminException::userNotFound($targetUserId);
        }

        $member = WorkspaceMember::query()->create([
            'workspace_id' => $workspaceId,
            'user_id' => $targetUserId,
            'role' => $role,
            'status' => WorkspaceMemberStatus::Active,
            'joined_at' => now(),
        ]);

        $this->audit->memberAdded($workspaceId, $actorUserId, $targetUserId, $role);

        return $member;
    }

    /**
     * Change a member's role (Owner-only). The last Owner can neither be
     * demoted nor skipped over.
     *
     * @throws WorkspaceAdminException for an unknown workspace, a non-Owner
     *                                 actor, a target without membership, or
     *                                 last-Owner demotion
     */
    public function changeRole(
        int $workspaceId,
        int $actorUserId,
        int $targetUserId,
        WorkspaceRole $role,
    ): WorkspaceMember {
        $this->assertWorkspaceExists($workspaceId);
        $this->assertOwner($workspaceId, $actorUserId);

        $member = $this->requireMemberRow($workspaceId, $targetUserId);
        if ($member->role === $role) {
            return $member;
        }

        $oldRole = $member->role;
        if ($oldRole === WorkspaceRole::Owner) {
            $this->assertNotLastOwner($workspaceId, $targetUserId);
        }

        $member->role = $role;
        $member->save();

        $this->audit->roleChanged($workspaceId, $actorUserId, $targetUserId, $oldRole, $role);

        return $member;
    }

    /**
     * Remove a member by deleting the row (Owner-only; last-Owner
     * protected). The audit trail keeps the history instead of the row.
     *
     * @throws WorkspaceAdminException for an unknown workspace, a non-Owner
     *                                 actor, a target without membership, or
     *                                 last-Owner removal
     */
    public function removeMember(int $workspaceId, int $actorUserId, int $targetUserId): void
    {
        $this->assertWorkspaceExists($workspaceId);
        $this->assertOwner($workspaceId, $actorUserId);

        $member = $this->requireMemberRow($workspaceId, $targetUserId);
        if ($member->role === WorkspaceRole::Owner) {
            $this->assertNotLastOwner($workspaceId, $targetUserId);
        }

        $role = $member->role;
        $member->delete();

        $this->audit->memberRemoved($workspaceId, $actorUserId, $targetUserId, $role);
    }

    /**
     * Unique, deterministic slug derived from the name; collisions get a
     * numeric suffix, and a lost unique-index race retries the next suffix.
     *
     * @throws WorkspaceAdminException when every candidate is taken
     */
    private function createWithUniqueSlug(string $name): Workspace
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'workspace';
        }

        $ordinal = 1;
        for ($attempt = 0; $attempt < self::MAX_SLUG_ATTEMPTS; $attempt++) {
            $slug = $ordinal === 1 ? $base : $base.'-'.$ordinal;

            if (Workspace::query()->where('slug', $slug)->exists()) {
                $ordinal++;
                continue;
            }

            try {
                return Workspace::query()->create([
                    'name' => $name,
                    'slug' => $slug,
                ]);
            } catch (QueryException) {
                $ordinal++;
            }
        }

        throw WorkspaceAdminException::slugCollision($base);
    }

    /**
     * @throws WorkspaceAdminException when the workspace does not exist
     */
    private function assertWorkspaceExists(int $workspaceId): Workspace
    {
        $workspace = Workspace::query()->find($workspaceId);
        if ($workspace === null) {
            throw WorkspaceAdminException::workspaceNotFound($workspaceId);
        }

        return $workspace;
    }

    /**
     * @throws WorkspaceAdminException unless the actor is an active Owner
     */
    private function assertOwner(int $workspaceId, int $actorUserId): void
    {
        $member = $this->memberRow($workspaceId, $actorUserId);
        if ($member === null
            || $member->status !== WorkspaceMemberStatus::Active
            || $member->role !== WorkspaceRole::Owner) {
            throw WorkspaceAdminException::notOwner($workspaceId, $actorUserId);
        }
    }

    /**
     * @throws WorkspaceAdminException unless the actor is an active member
     */
    private function assertActiveMember(int $workspaceId, int $actorUserId): void
    {
        if (! $this->isMember($workspaceId, $actorUserId)) {
            throw WorkspaceAdminException::notMember($workspaceId, $actorUserId);
        }
    }

    /**
     * @throws WorkspaceAdminException when the target holds no membership
     */
    private function requireMemberRow(int $workspaceId, int $targetUserId): WorkspaceMember
    {
        $member = $this->memberRow($workspaceId, $targetUserId);
        if ($member === null) {
            throw WorkspaceAdminException::memberNotFound($workspaceId, $targetUserId);
        }

        return $member;
    }

    /**
     * @throws WorkspaceAdminException when the target is the only Owner
     */
    private function assertNotLastOwner(int $workspaceId, int $targetUserId): void
    {
        $owners = WorkspaceMember::query()
            ->where('workspace_id', $workspaceId)
            ->where('role', WorkspaceRole::Owner->value)
            ->count();

        if ($owners <= 1) {
            throw WorkspaceAdminException::lastOwnerProtection($workspaceId);
        }
    }

    private function memberRow(int $workspaceId, int $userId): ?WorkspaceMember
    {
        return WorkspaceMember::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * @return Builder<WorkspaceMember>
     */
    private function activeMemberQuery(int $workspaceId, int $userId): Builder
    {
        return WorkspaceMember::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->where('status', WorkspaceMemberStatus::Active->value);
    }
}
