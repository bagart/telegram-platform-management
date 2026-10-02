<?php

declare(strict_types=1);

use BAGArt\TelegramBotManagement\Contracts\ChatAdministratorProbeContract;
use BAGArt\TelegramBotManagement\DTO\EntityAddVerification;
use BAGArt\TelegramBotManagement\Services\EntityAddVerifier;

/**
 * Phase 6 gap scan (ADR-002 SD2): the verifier is fail-closed even when the
 * probe itself misbehaves. Transport/API failures are the probe's contract
 * (ChatAdministratorProbeContract: "any transport/API failure returns
 * allowed=false") and HttpChatAdministratorProbeTest covers them; what was
 * uncovered is the verifier's reaction to a probe that BREAKS its contract
 * and throws — it must surface as an error, never as an allow.
 */

/**
 * Probe that violates its own contract by throwing instead of answering.
 */
final class GapThrowingProbe implements ChatAdministratorProbeContract
{
    public function verify(string $botId, int $chatId, int $initiatorTgId): EntityAddVerification
    {
        throw new \RuntimeException('probe transport exploded');
    }
}

describe('EntityAddVerifier with a broken probe', function () {
    it('surfaces the probe failure as an error instead of an allow (fail-closed)', function () {
        $verifier = new EntityAddVerifier(new GapThrowingProbe());

        expect(fn (): mixed => $verifier->verify('123456789', -100123, 42))
            ->toThrow(\RuntimeException::class, 'probe transport exploded');

        // No EntityAddVerification is ever produced, so the surface layer can
        // never read `allowed = true` out of a failed verification — the
        // exception is the failure signal, and it bubbles to the caller.
    });
});
