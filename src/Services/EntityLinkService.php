<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Services;

use BAGArt\TelegramBotManagement\Enums\EntityKind;
use BAGArt\TelegramBotManagement\Exceptions\EntityException;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Models\TgEntity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Linking of Telegram entities (channel / chat group / bot) into a workspace
 * (D2, ADR-003). Every query is workspace-scoped — there is deliberately no
 * unscoped read path.
 *
 * Semantics:
 *  - link() is an idempotent upsert keyed by (workspace, kind, target): a
 *    repeat call returns the existing row refreshed (title, username,
 *    linked_at); linked_by keeps the original linker.
 *  - Chat entities (channel / chat_group) are exclusively claimed: one chat
 *    may be linked in exactly one workspace at a time — two tenants
 *    mirroring T1 rights over the same chat would produce divergent grants
 *    and ban decisions. Bot entities are exempt (catalog bots are shared
 *    actors, linkable in many workspaces in parallel).
 *  - unlink() is same-workspace only and returns false for anything it does
 *    not find — including an entity that exists in another workspace.
 *  - actorUserId 0 = system/surface-initiated link (stored as linked_by NULL).
 */
final class EntityLinkService
{
    /**
     * @throws EntityException when the kind/target pair is inconsistent, the
     *                         bot is unknown to the platform, the chat is
     *                         already linked in this workspace under another
     *                         kind, or the chat is claimed by another workspace
     */
    public function link(
        int $workspaceId,
        EntityKind $kind,
        ?int $externalId,
        ?string $botId,
        int $actorUserId,
        ?string $title = null,
        ?string $username = null,
    ): TgEntity {
        $botId = $botId === '' ? null : $botId;
        $this->assertTargetFitsKind($kind, $externalId, $botId);

        if ($kind === EntityKind::Bot) {
            if (! TgBot::query()->where('bot_id', $botId)->exists()) {
                throw EntityException::botNotInPlatform((string) $botId);
            }
        }

        $existing = $this->findInWorkspace($workspaceId, $kind, $externalId, $botId);
        if ($existing !== null) {
            $existing->title = $title;
            $existing->username = $username;
            $existing->linked_at = Carbon::now();
            $existing->save();

            return $existing;
        }

        if ($kind !== EntityKind::Bot) {
            $this->assertChatNotClaimed($workspaceId, (int) $externalId);
        }

        return TgEntity::query()->create([
            'workspace_id' => $workspaceId,
            'kind' => $kind,
            'external_id' => $externalId,
            'bot_id' => $botId,
            'title' => $title,
            'username' => $username,
            'linked_by' => $actorUserId > 0 ? $actorUserId : null,
            'linked_at' => Carbon::now(),
        ]);
    }

    /**
     * Remove one entity from one workspace.
     *
     * Returns false when the id does not exist or belongs to another
     * workspace — never unscoped, never throws.
     */
    public function unlink(int $workspaceId, int $entityId): bool
    {
        return TgEntity::query()
            ->where('workspace_id', $workspaceId)
            ->where('id', $entityId)
            ->delete() > 0;
    }

    /**
     * Workspace-scoped listing (optionally narrowed by kind). Never unscoped.
     *
     * @return Collection<int, TgEntity>
     */
    public function list(int $workspaceId, ?EntityKind $kind = null): Collection
    {
        return TgEntity::query()
            ->where('workspace_id', $workspaceId)
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind->value))
            ->orderBy('id')
            ->get();
    }

    private function assertTargetFitsKind(EntityKind $kind, ?int $externalId, ?string $botId): void
    {
        $fits = $kind === EntityKind::Bot
            ? ($botId !== null && $externalId === null)
            : ($externalId !== null && $botId === null);

        if (! $fits) {
            throw EntityException::invalidKindTarget($kind, $externalId, $botId);
        }
    }

    private function findInWorkspace(
        int $workspaceId,
        EntityKind $kind,
        ?int $externalId,
        ?string $botId,
    ): ?TgEntity {
        return TgEntity::query()
            ->where('workspace_id', $workspaceId)
            ->where('kind', $kind->value)
            ->when(
                $kind === EntityKind::Bot,
                fn ($query) => $query->where('bot_id', $botId),
                fn ($query) => $query->where('external_id', $externalId),
            )
            ->first();
    }

    /**
     * Chat exclusivity: the same chat id may exist neither under another
     * kind in this workspace nor in any other workspace.
     */
    private function assertChatNotClaimed(int $workspaceId, int $externalId): void
    {
        $here = TgEntity::query()
            ->where('workspace_id', $workspaceId)
            ->where('external_id', $externalId)
            ->first();

        if ($here !== null) {
            throw EntityException::alreadyLinkedInWorkspace($externalId, $here->kind);
        }

        $elsewhere = TgEntity::query()
            ->where('external_id', $externalId)
            ->where('workspace_id', '!=', $workspaceId)
            ->exists();

        if ($elsewhere) {
            throw EntityException::crossWorkspaceLink($externalId, $workspaceId);
        }
    }
}
