<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Services;

use BAGArt\TelegramBotManagement\Enums\EntityKind;
use BAGArt\TelegramBotManagement\Enums\WorkspaceMemberStatus;
use BAGArt\TelegramBotManagement\Enums\WorkspaceRole;
use BAGArt\TelegramBotManagement\Models\TgEntity;
use BAGArt\TelegramBotManagement\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bot ownership resolution over the workspace tenancy model (D7/D11,
 * ADR-001/ADR-003).
 *
 * A platform user owns a bot iff they hold an ACTIVE membership with role
 * Owner in a workspace that links the bot via tg_entities (kind=bot,
 * bot_id). A user may own through several workspaces — the answer is the
 * union across all of them.
 *
 * Legacy tg_bot_owners is an ownership FACT only, never a rights source:
 * this service deliberately does not read it, and no production code writes
 * it (D11 — rows are disposable). All queries hit indexed columns
 * (workspace_members unique(workspace_id,user_id) + index(user_id,status),
 * tg_entities unique(workspace_id,kind,bot_id)) via whereIn subqueries.
 */
final class BotOwnershipResolver
{
    /**
     * Whether the user is a workspace owner of a workspace linking the bot.
     */
    public function isOwner(int $userId, string $botId): bool
    {
        return $this->ownedBotEntities($userId)
            ->where('bot_id', $botId)
            ->exists();
    }

    /**
     * All bots owned by the user across their workspaces, ordered by bot_id.
     *
     * @return list<string>
     */
    public function ownedBotIds(int $userId): array
    {
        return $this->ownedBotEntities($userId)
            ->orderBy('bot_id')
            ->pluck('bot_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Inverse of isOwner(): platform user ids holding ACTIVE Owner
     * memberships in workspaces linking the bot, ordered by user id.
     *
     * @return list<int>
     */
    public function ownerUserIds(string $botId): array
    {
        $workspaceIds = TgEntity::query()
            ->where('kind', EntityKind::Bot->value)
            ->where('bot_id', $botId)
            ->pluck('workspace_id');

        if ($workspaceIds->isEmpty()) {
            return [];
        }

        return WorkspaceMember::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where('role', WorkspaceRole::Owner->value)
            ->where('status', WorkspaceMemberStatus::Active->value)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Bot entities reachable from the user's owned workspaces.
     *
     * @return Builder<TgEntity>
     */
    private function ownedBotEntities(int $userId): Builder
    {
        return TgEntity::query()
            ->where('kind', EntityKind::Bot->value)
            ->whereIn('workspace_id', $this->ownerWorkspaceIds($userId));
    }

    /**
     * Workspace ids where the user is an ACTIVE Owner (subquery, never
     * materialized — keeps the whole resolution to indexed SQL).
     *
     * @return Builder<WorkspaceMember>
     */
    private function ownerWorkspaceIds(int $userId): Builder
    {
        return WorkspaceMember::query()
            ->select('workspace_id')
            ->where('user_id', $userId)
            ->where('role', WorkspaceRole::Owner->value)
            ->where('status', WorkspaceMemberStatus::Active->value);
    }
}
