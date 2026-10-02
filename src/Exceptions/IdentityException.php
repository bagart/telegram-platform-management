<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Exceptions;

/**
 * Domain failure of the telegram-identity link/unlink flow (D7/D9):
 * conflicts on the unique users.telegram_id, unknown subjects or an
 * unlink that would lock the account out of every recovery channel.
 */
final class IdentityException extends \RuntimeException
{
    public static function userNotFound(int $userId): self
    {
        return new self("User [{$userId}] does not exist.");
    }

    public static function telegramAlreadyLinked(int $telegramId, int $ownerUserId): self
    {
        return new self("Telegram id [{$telegramId}] is already linked to user [{$ownerUserId}].");
    }

    public static function alreadyLinkedToDifferentTelegram(int $userId, int $currentTelegramId): self
    {
        return new self("User [{$userId}] is already linked to telegram [{$currentTelegramId}] — unlink first.");
    }

    public static function recoveryChannelRequired(int $userId): self
    {
        return new self("User [{$userId}] has no recovery email — unlinking would lock the account out.");
    }
}
