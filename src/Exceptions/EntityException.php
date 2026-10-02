<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Exceptions;

use BAGArt\TelegramBotManagement\Enums\EntityKind;

/**
 * Domain failure of the entity link/unlink flow (D2, ADR-003): a target that
 * does not fit the requested kind, a bot the platform does not know, or a
 * link attempt that crosses workspace boundaries.
 */
final class EntityException extends \RuntimeException
{
    public static function invalidKindTarget(EntityKind $kind, ?int $externalId, ?string $botId): self
    {
        return new self(sprintf(
            'Kind [%s] does not match the supplied target (external_id=%s, bot_id=%s).',
            $kind->value,
            $externalId === null ? 'null' : (string) $externalId,
            $botId ?? 'null',
        ));
    }

    public static function botNotInPlatform(string $botId): self
    {
        return new self("Bot [{$botId}] does not exist in the platform registry.");
    }

    public static function alreadyLinkedInWorkspace(int $externalId, EntityKind $existingKind): self
    {
        return new self(
            "Chat [{$externalId}] is already linked in this workspace as [{$existingKind->value}].",
        );
    }

    public static function crossWorkspaceLink(int $externalId, int $workspaceId): self
    {
        return new self(
            "Chat [{$externalId}] is already linked in another workspace — unlink it there before linking into workspace [{$workspaceId}].",
        );
    }
}
