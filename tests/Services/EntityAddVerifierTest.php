<?php

declare(strict_types=1);

use BAGArt\TelegramBotManagement\Contracts\ChatAdministratorProbeContract;
use BAGArt\TelegramBotManagement\DTO\EntityAddVerification;
use BAGArt\TelegramBotManagement\Services\EntityAddVerifier;

/**
 * Configurable fake probe: returns a preset verdict and records the call
 * so tests can assert the verifier forwards arguments untouched.
 */
final class FakeEntityAddProbe implements ChatAdministratorProbeContract
{
    /** @var array{botId: string, chatId: int, initiatorTgId: int}|null */
    public ?array $received = null;

    public function __construct(
        private readonly EntityAddVerification $result,
    ) {
    }

    public function verify(string $botId, int $chatId, int $initiatorTgId): EntityAddVerification
    {
        $this->received = ['botId' => $botId, 'chatId' => $chatId, 'initiatorTgId' => $initiatorTgId];

        return $this->result;
    }
}

describe('EntityAddVerifier', function () {
    it('passes through an allow verdict and forwards the arguments', function () {
        $probe = new FakeEntityAddProbe(new EntityAddVerification(true, true, true, null));
        $verifier = new EntityAddVerifier($probe);

        $result = $verifier->verify('123456789', -100123, 42);

        expect($result->allowed)->toBeTrue()
            ->and($result->initiatorIsAdmin)->toBeTrue()
            ->and($result->botHasRequiredRights)->toBeTrue()
            ->and($result->reason)->toBeNull()
            ->and($probe->received)->toBe([
                'botId' => '123456789',
                'chatId' => -100123,
                'initiatorTgId' => 42,
            ]);
    });

    it('rejects when the probe denies, keeping the reason', function () {
        $verifier = new EntityAddVerifier(
            new FakeEntityAddProbe(new EntityAddVerification(false, false, true, 'initiator_not_admin')),
        );

        $result = $verifier->verify('123456789', -100123, 42);

        expect($result->allowed)->toBeFalse()
            ->and($result->reason)->toBe('initiator_not_admin');
    });

    it('fails closed when the probe claims allow but a flag is missing', function () {
        $verifier = new EntityAddVerifier(
            new FakeEntityAddProbe(new EntityAddVerification(true, false, true, null)),
        );

        $result = $verifier->verify('123456789', -100123, 42);

        expect($result->allowed)->toBeFalse()
            ->and($result->initiatorIsAdmin)->toBeFalse()
            ->and($result->botHasRequiredRights)->toBeTrue()
            ->and($result->reason)->toBe('verification_failed');
    });
});
