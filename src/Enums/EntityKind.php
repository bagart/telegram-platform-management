<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Enums;

/**
 * Kind of a managed Telegram entity (D2, ADR-003): channels and chat groups
 * are identified by their Telegram chat id; bots are identified by their
 * bot row (they are actors, not chats).
 *
 * @see https://core.telegram.org/bots/api#chat
 */
enum EntityKind: string
{
    case Channel = 'channel';

    case ChatGroup = 'chat_group';

    case Bot = 'bot';
}
