<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\DTO;

/**
 * Result of the server-side entity-add verification (ADR-002 SD2): one
 * getChatAdministrators call proves both the initiator's adminship of the
 * target chat and the bot's presence as an administrator of it.
 *
 * `allowed` is the overall verdict; `reason` is set when denied so the
 * surface layer can translate/log it. Read-only — produced by the probe,
 * normalized by EntityAddVerifier, consumed by Telegram surfaces.
 */
final readonly class EntityAddVerification
{
    public function __construct(
        public bool $allowed,
        public bool $initiatorIsAdmin,
        public bool $botHasRequiredRights,
        public ?string $reason = null,
    ) {
    }
}
