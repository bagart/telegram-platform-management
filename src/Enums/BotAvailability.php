<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Enums;

/**
 * Catalog visibility of a platform bot (D12, ADR-004): public bots are free
 * for every workspace; private bots require a superadmin-managed entitlement
 * flag on the workspace (billing deferred).
 */
enum BotAvailability: string
{
    case Public = 'public';

    case Private = 'private';
}
