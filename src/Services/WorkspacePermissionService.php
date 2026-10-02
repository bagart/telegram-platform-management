<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Services;

use BAGArt\TelegramBotAccess\AccessControlContract;
use BAGArt\TelegramBotAccess\AccessDecision;
use BAGArt\TelegramBotAccess\AccessRequest;
use BAGArt\TelegramBotAccess\ActorContext;
use BAGArt\TelegramBotAccess\Application\DenyAccess;
use BAGArt\TelegramBotAccess\Application\GrantAccess;
use BAGArt\TelegramBotAccess\Application\RevokeAccess;
use BAGArt\TelegramBotAccess\Grant;
use BAGArt\TelegramBotAccess\GrantEffect;
use BAGArt\TelegramBotAccess\GrantScope;
use BAGArt\TelegramBotAccess\Permission\NonGrantableCapabilityException;
use BAGArt\TelegramBotAccess\Permission\PermissionCatalog;
use BAGArt\TelegramBotAccess\Permission\Tier;

/**
 * T2 platform-grant management over the access module (D3/D5/D8, ADR-001/
 * ADR-002/ADR-003): the management surface for granting, denying and
 * revoking platform-granted member capabilities, plus read-side decisions.
 *
 * Tier rules enforced here (mirrored by GrantAccess for T1):
 *  - T1 (mirrored Telegram adminship) → NonGrantableCapabilityException —
 *    only the Telegram mirror engine may produce that authority;
 *  - T0 (membership baseline) → InvalidArgumentException — membership is a
 *    workspace_members fact, not a grant;
 *  - T2 → grantable.
 *
 * Scope mapping: chatId === null → Workspace scope pinned to workspace_id
 * (covers current and future entities of the workspace); otherwise Chat
 * scope pinned to chat_id with workspace_id NULL (schema constraint — a
 * grant never carries both).
 *
 * MEMBERSHIP INTERACTION: AccessControlContract::decide() has NO membership
 * rung — it evaluates grants (and policy/role fallback) only. Surfaces must
 * combine WorkspaceAdminService::isMember() (T0 presence) with can()
 * (T1/T2 standing): a member without grants is still denied T2 capabilities
 * by default, and a grant without membership does not make the subject a
 * workspace member. Grant changes are audited by the access module's event
 * listener (GrantCreated/GrantRevoked); membership changes by
 * WorkspaceAuditRecorder.
 */
final class WorkspacePermissionService
{
    public function __construct(
        private readonly GrantAccess $grantAccess,
        private readonly DenyAccess $denyAccess,
        private readonly RevokeAccess $revokeAccess,
        private readonly AccessControlContract $accessControl,
        private readonly PermissionCatalog $catalog,
    ) {
    }

    /**
     * Grant a T2 capability: workspace-wide when $chatId is null, otherwise
     * scoped to a single chat. Idempotent upsert (same identity + same
     * effect returns the existing grant).
     *
     * @throws NonGrantableCapabilityException for T1 capabilities
     * @throws \InvalidArgumentException for T0 capabilities (membership is not a grant)
     */
    public function grantT2(
        int $workspaceId,
        string $botId,
        int $targetUserId,
        string $capability,
        ?int $chatId = null,
        ?int $actorUserId = null,
        ?\DateTimeInterface $expiresAt = null,
    ): Grant {
        $this->assertGrantable($capability);

        $grant = new Grant(
            botId: $botId,
            subjectId: (string) $targetUserId,
            scope: $this->scope($chatId),
            capability: $capability,
            effect: GrantEffect::Allow,
            chatId: $chatId,
            workspaceId: $chatId === null ? $workspaceId : null,
            grantedAt: now(),
            expiresAt: $expiresAt,
        );

        return $this->grantAccess->execute($grant, $this->actor($actorUserId));
    }

    /**
     * Explicit DENY for a T2 capability — the narrowing half of
     * deny-by-default inheritance (D5): a chat-scope deny beats any
     * workspace-scope allow at decide() time.
     *
     * @throws NonGrantableCapabilityException for T1 capabilities
     * @throws \InvalidArgumentException for T0 capabilities (membership is not a grant)
     */
    public function denyT2(
        int $workspaceId,
        string $botId,
        int $targetUserId,
        string $capability,
        ?int $chatId = null,
        ?int $actorUserId = null,
    ): Grant {
        $this->assertGrantable($capability);

        return $this->denyAccess->execute(
            botId: $botId,
            subjectId: (string) $targetUserId,
            capability: $capability,
            actor: $this->actor($actorUserId),
            chatId: $chatId,
            workspaceId: $chatId === null ? $workspaceId : null,
        );
    }

    /**
     * Revoke a previously granted T2 capability (workspace or chat scope).
     *
     * @return bool true when a grant row existed and was deleted
     */
    public function revokeT2(
        int $workspaceId,
        string $botId,
        int $targetUserId,
        string $capability,
        ?int $chatId = null,
        ?int $actorUserId = null,
    ): bool {
        return $this->revokeAccess->execute(
            botId: $botId,
            subjectId: (string) $targetUserId,
            capability: $capability,
            actor: $this->actor($actorUserId),
            chatId: $chatId,
            workspaceId: $chatId === null ? $workspaceId : null,
        );
    }

    /**
     * Read-side decision: may the user perform the capability? Workspace
     * requests (chatId null) carry workspaceId so workspace-scope grants are
     * visible; chat requests stay chat-scoped.
     *
     * Note: this answers grant standing only — pair with
     * WorkspaceAdminService::isMember() for the T0 membership rung.
     */
    public function can(
        int $workspaceId,
        string $botId,
        int $userId,
        string $capability,
        ?int $chatId = null,
    ): AccessDecision {
        return $this->accessControl->decide(new AccessRequest(
            botId: $botId,
            subjectId: (string) $userId,
            capability: $capability,
            chatId: $chatId,
            workspaceId: $chatId === null ? $workspaceId : null,
        ));
    }

    /**
     * Tier gate before touching the repository.
     *
     * @throws NonGrantableCapabilityException when the capability is tier T1
     * @throws \InvalidArgumentException when the capability is tier T0
     */
    private function assertGrantable(string $capability): void
    {
        $tier = $this->catalog->tier($capability);

        if ($tier === Tier::T1) {
            throw NonGrantableCapabilityException::forCapability($capability);
        }

        if ($tier === Tier::T0) {
            throw new \InvalidArgumentException(
                "Capability '{$capability}' is the T0 membership baseline — "
                . 'membership lives in workspace_members and is not a platform grant.',
            );
        }
    }

    private function scope(?int $chatId): GrantScope
    {
        return $chatId === null ? GrantScope::Workspace : GrantScope::Chat;
    }

    private function actor(?int $actorUserId): ActorContext
    {
        return new ActorContext(
            subjectId: $actorUserId !== null ? (string) $actorUserId : 'system',
            source: 'management',
        );
    }
}
