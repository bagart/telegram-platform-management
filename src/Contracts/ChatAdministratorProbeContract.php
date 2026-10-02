<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Contracts;

use BAGArt\TelegramBotManagement\DTO\EntityAddVerification;

/**
 * Server-side check that the initiator administers the target chat and that
 * the bot holds the required rights there (ADR-002 SD2).
 *
 * Narrow seam on purpose: the management package decides nothing about HTTP
 * — the default implementation queries Telegram getChatAdministrators, tests
 * bind a fake, and surfaces depend only on this contract.
 */
interface ChatAdministratorProbeContract
{
    /**
     * Fail-closed verification: any transport/API failure returns
     * allowed=false with a machine-readable reason instead of throwing.
     */
    public function verify(string $botId, int $chatId, int $initiatorTgId): EntityAddVerification;
}
