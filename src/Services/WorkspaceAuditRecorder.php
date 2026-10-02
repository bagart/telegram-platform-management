<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Services;

use BAGArt\TelegramBotAudit\AuditActor;
use BAGArt\TelegramBotAudit\AuditEntry;
use BAGArt\TelegramBotAudit\AuditException;
use BAGArt\TelegramBotAudit\AuditFailurePolicy;
use BAGArt\TelegramBotAudit\AuditFailurePolicyResolver;
use BAGArt\TelegramBotAudit\AuditSinkContract;
use BAGArt\TelegramBotAudit\AuditTarget;
use BAGArt\TelegramBotAudit\CorrelationContext;
use BAGArt\TelegramBotManagement\Enums\WorkspaceRole;
use Illuminate\Support\Facades\Log;

/**
 * Workspace-membership audit trail (Phase 3 item 6, ADR-003: "permission /
 * membership changes emit audit entries via AuditSinkContract").
 *
 * Grant changes are audited automatically by the access module's
 * RecordAccessControlEvents listener (GrantCreated/GrantRevoked); membership
 * has no access-package event shape, so this recorder appends directly to
 * the sink instead of abusing grant events. Operation keys are namespaced
 * module strings — AuditEntry accepts Operation|string, so membership
 * operations need no new cases in the (read-only) audit Operation enum.
 *
 * The sink is resolved lazily per append through the container: hosts and
 * tests may rebind AuditSinkContract, and a host without the audit module
 * simply drops membership entries (log-only fallback).
 */
final class WorkspaceAuditRecorder
{
    public const string OPERATION_WORKSPACE_CREATED = 'workspace.created';
    public const string OPERATION_MEMBER_ADDED = 'workspace.member.added';
    public const string OPERATION_MEMBER_REMOVED = 'workspace.member.removed';
    public const string OPERATION_ROLE_CHANGED = 'workspace.member.role.changed';

    public function workspaceCreated(int $workspaceId, string $slug, int $creatorUserId): void
    {
        $this->record(
            operation: self::OPERATION_WORKSPACE_CREATED,
            subjectType: 'workspace',
            subjectId: (string) $workspaceId,
            workspaceId: $workspaceId,
            actorUserId: $creatorUserId,
            memberUserId: $creatorUserId,
            oldState: null,
            newState: ['slug' => $slug, 'owner_user_id' => $creatorUserId],
            metadata: ['slug' => $slug],
        );
    }

    public function memberAdded(
        int $workspaceId,
        int $actorUserId,
        int $memberUserId,
        WorkspaceRole $role,
    ): void {
        $this->record(
            operation: self::OPERATION_MEMBER_ADDED,
            workspaceId: $workspaceId,
            actorUserId: $actorUserId,
            memberUserId: $memberUserId,
            oldState: null,
            newState: ['role' => $role->value, 'status' => 'active'],
        );
    }

    public function memberRemoved(
        int $workspaceId,
        int $actorUserId,
        int $memberUserId,
        WorkspaceRole $role,
    ): void {
        $this->record(
            operation: self::OPERATION_MEMBER_REMOVED,
            workspaceId: $workspaceId,
            actorUserId: $actorUserId,
            memberUserId: $memberUserId,
            oldState: ['role' => $role->value],
            newState: null,
        );
    }

    public function roleChanged(
        int $workspaceId,
        int $actorUserId,
        int $memberUserId,
        WorkspaceRole $oldRole,
        WorkspaceRole $newRole,
    ): void {
        $this->record(
            operation: self::OPERATION_ROLE_CHANGED,
            workspaceId: $workspaceId,
            actorUserId: $actorUserId,
            memberUserId: $memberUserId,
            oldState: ['role' => $oldRole->value],
            newState: ['role' => $newRole->value],
        );
    }

    /**
     * Append one membership entry to the audit sink.
     *
     * Failure handling mirrors the audit module's listeners: the resolved
     * policy decides between rethrow (FAIL_CLOSED — caller must roll back)
     * and a warning log (FAIL_OPEN).
     *
     * @param  array<string, mixed>|null  $oldState
     * @param  array<string, mixed>|null  $newState
     * @param  array<string, mixed>  $metadata
     * @param  string  $subjectType  Target entity type; defaults to a membership row.
     * @param  string|null  $subjectId  Target identifier; defaults to "workspaceId:memberUserId".
     */
    private function record(
        string $operation,
        int $workspaceId,
        int $actorUserId,
        int $memberUserId,
        ?array $oldState,
        ?array $newState,
        array $metadata = [],
        string $subjectType = 'workspace_member',
        ?string $subjectId = null,
    ): void {
        if (! app()->bound(AuditSinkContract::class)) {
            Log::info('workspace.membership', [
                'operation' => $operation,
                'workspace_id' => $workspaceId,
                'actor_user_id' => $actorUserId,
                'user_id' => $memberUserId,
            ]);

            return;
        }

        $entry = AuditEntry::now(
            id: AuditEntry::generateId(),
            actor: new AuditActor(
                type: AuditActor::TYPE_USER,
                id: (string) $actorUserId,
            ),
            target: new AuditTarget(
                botId: null,
                subjectType: $subjectType,
                subjectId: $subjectId ?? "{$workspaceId}:{$memberUserId}",
            ),
            operation: $operation,
            oldState: $oldState,
            newState: $newState,
            source: 'management',
            correlationId: $this->correlationId(),
            metadata: $metadata + [
                'workspace_id' => $workspaceId,
                'user_id' => $memberUserId,
            ],
        );

        try {
            app(AuditSinkContract::class)->append($entry);
        } catch (\Throwable $e) {
            if ($this->failurePolicy($entry) === AuditFailurePolicy::FailClosed) {
                throw new AuditException(
                    "Audit append failed for operation '{$operation}': {$e->getMessage()}",
                    previous: $e,
                );
            }

            Log::warning('workspace audit append failed (fail_open)', [
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function correlationId(): ?string
    {
        if (! app()->bound(CorrelationContext::class)) {
            return null;
        }

        return app(CorrelationContext::class)->id();
    }

    private function failurePolicy(AuditEntry $entry): AuditFailurePolicy
    {
        if (! app()->bound(AuditFailurePolicyResolver::class)) {
            return AuditFailurePolicy::FailOpen;
        }

        return app(AuditFailurePolicyResolver::class)->resolve($entry);
    }
}
