<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Services;

use BAGArt\TelegramBotManagement\Enums\EntityKind;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Models\TgEntity;
use Illuminate\Database\Eloquent\Collection;

/**
 * Workspace roster of connected bots (ADR-004): bots linked as entities of
 * the workspace (kind=bot). Linking is composed from EntityLinkService —
 * no duplicate link logic lives here.
 *
 * NOTE: no T1 permission gating here on purpose — who may see/connect a bot
 * is decided by the surface layer (later wave) on top of the permission
 * catalog; this class only answers "which bots does the workspace hold".
 */
final class ConnectedBotRoster
{
    public function __construct(
        private readonly EntityLinkService $entities,
    ) {
    }

    /**
     * @return Collection<int, TgBot>
     */
    public function botsForWorkspace(int $workspaceId): Collection
    {
        $botIds = TgEntity::query()
            ->where('workspace_id', $workspaceId)
            ->where('kind', EntityKind::Bot->value)
            ->pluck('bot_id')
            ->filter()
            ->values();

        if ($botIds->isEmpty()) {
            return new Collection();
        }

        return TgBot::query()
            ->whereIn('bot_id', $botIds)
            ->orderBy('bot_id')
            ->get();
    }

    /**
     * Link a catalog bot into the workspace (idempotent via EntityLinkService).
     *
     * @throws \BAGArt\TelegramBotManagement\Exceptions\EntityException
     */
    public function linkCatalogBot(
        int $workspaceId,
        string $botId,
        int $actorUserId,
        ?string $title = null,
        ?string $username = null,
    ): TgEntity {
        return $this->entities->link(
            workspaceId: $workspaceId,
            kind: EntityKind::Bot,
            externalId: null,
            botId: $botId,
            actorUserId: $actorUserId,
            title: $title,
            username: $username,
        );
    }
}
