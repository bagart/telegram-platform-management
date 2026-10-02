<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Enums;

/**
 * Bot ecosystem discriminator (D12, ADR-004): platform bots are managed by
 * the platform itself (tokens held in tg_bots); external bots are connected
 * by a workspace owner.
 */
enum BotClass: string
{
    case Platform = 'platform';

    case External = 'external';
}
