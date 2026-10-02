<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Exceptions;

/**
 * Domain failure of workspace administration (D1/D10, ADR-001): bad input
 * on creation, an actor without Owner standing, an unknown subject, or an
 * attempt to demote/remove the last Owner of a workspace.
 */
final class WorkspaceAdminException extends \RuntimeException
{
    public static function invalidName(string $name): self
    {
        $length = mb_strlen($name);

        return new self(
            "Workspace name must be 1-120 characters, got [{$length}] for [{$name}].",
        );
    }

    public static function slugCollision(string $base): self
    {
        return new self(
            "Could not derive a unique slug from [{$base}] — all candidates are taken.",
        );
    }

    public static function workspaceNotFound(int $workspaceId): self
    {
        return new self("Workspace [{$workspaceId}] does not exist.");
    }

    public static function notMember(int $workspaceId, int $userId): self
    {
        return new self("User [{$userId}] is not an active member of workspace [{$workspaceId}].");
    }

    public static function notOwner(int $workspaceId, int $userId): self
    {
        return new self("User [{$userId}] is not an Owner of workspace [{$workspaceId}].");
    }

    public static function userNotFound(int $userId): self
    {
        return new self("User [{$userId}] does not exist.");
    }

    public static function memberNotFound(int $workspaceId, int $userId): self
    {
        return new self("User [{$userId}] holds no membership in workspace [{$workspaceId}].");
    }

    public static function lastOwnerProtection(int $workspaceId): self
    {
        return new self(
            "Workspace [{$workspaceId}] must keep at least one Owner — promote another Owner first.",
        );
    }
}
