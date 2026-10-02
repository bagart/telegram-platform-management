<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Enums;

/**
 * Workspace membership lifecycle: Active members participate normally,
 * Suspended keeps the row (history) but revokes access.
 */
enum WorkspaceMemberStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
