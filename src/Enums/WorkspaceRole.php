<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Enums;

/**
 * Workspace membership role (ADR-001): Owner is the membership authority
 * of the workspace; Member is a regular participant.
 *
 * @see https://core.telegram.org/bots/api#chatmemberadministrator
 */
enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Member = 'member';
}
