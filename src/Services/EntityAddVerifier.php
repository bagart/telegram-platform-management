<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Services;

use BAGArt\TelegramBotManagement\Contracts\ChatAdministratorProbeContract;
use BAGArt\TelegramBotManagement\DTO\EntityAddVerification;

/**
 * Entity-add verification (ADR-002 SD2): ONE server-side
 * getChatAdministrators call proves the initiator administers the target
 * chat AND the bot holds its required rights there.
 *
 * Flow split (services stay orthogonal): verify() answers only "may this
 * entity be added?". Registering the entity (EntityLinkService::link) and
 * the ongoing my_chat_member sync are explicit separate calls made by the
 * surface layer — this class never touches storage.
 */
final class EntityAddVerifier
{
    public function __construct(
        private readonly ChatAdministratorProbeContract $probe,
    ) {
    }

    /**
     * Fail-closed: a denial passes through unchanged, and an "allowed" probe
     * result with a missing flag is re-denied instead of trusted.
     */
    public function verify(string $botId, int $chatId, int $initiatorTgId): EntityAddVerification
    {
        $result = $this->probe->verify($botId, $chatId, $initiatorTgId);

        if (! $result->allowed) {
            return $result;
        }

        if ($result->initiatorIsAdmin && $result->botHasRequiredRights) {
            return $result;
        }

        return new EntityAddVerification(
            allowed: false,
            initiatorIsAdmin: $result->initiatorIsAdmin,
            botHasRequiredRights: $result->botHasRequiredRights,
            reason: $result->reason ?? 'verification_failed',
        );
    }
}
