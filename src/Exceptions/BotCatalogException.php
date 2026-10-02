<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Exceptions;

/**
 * Domain failure of the bot catalog / external-bot connect flow (D12/D13,
 * ADR-004): a platform bot cannot be re-registered as external, or the
 * owner-supplied token does not look like a Telegram bot token (format check
 * only — this service never calls the Bot API).
 */
final class BotCatalogException extends \RuntimeException
{
    public static function botExistsAsPlatform(string $botId): self
    {
        return new self("Bot [{$botId}] is a platform bot — external tokens do not apply to it.");
    }

    public static function invalidToken(): self
    {
        return new self('Token is empty or does not match the Telegram bot token format.');
    }
}
