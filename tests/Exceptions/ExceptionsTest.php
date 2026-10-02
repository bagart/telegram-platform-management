<?php

declare(strict_types=1);

use BAGArt\TelegramBot\Contracts\Exceptions\TelegramBotException;
use BAGArt\TelegramBotManagement\Exceptions\BotManagerException;
use BAGArt\TelegramBotManagement\Exceptions\StaleWriteException;

describe('Exceptions', function () {
    describe('BotManagerException', function () {
        it('extends RuntimeException', function () {
            $exception = new BotManagerException('test');

            expect($exception)->toBeInstanceOf(RuntimeException::class);
        });

        it('implements TelegramBotException contract', function () {
            $exception = new BotManagerException('test');

            expect($exception)->toBeInstanceOf(TelegramBotException::class);
        });

        it('carries a message', function () {
            $exception = new BotManagerException('something went wrong');

            expect($exception->getMessage())->toBe('something went wrong');
        });
    });

    describe('StaleWriteException', function () {
        it('extends RuntimeException', function () {
            $exception = new StaleWriteException(values: [], updatedAt: '');

            expect($exception)->toBeInstanceOf(RuntimeException::class);
        });

        it('is a final class', function () {
            $reflection = new ReflectionClass(StaleWriteException::class);

            expect($reflection->isFinal())->toBeTrue();
        });

        it('carries authoritative values and updatedAt', function () {
            $values = ['key' => 'value', 'count' => 42];
            $version = '2026-01-15T10:30:00.123456Z';

            $exception = new StaleWriteException(
                values: $values,
                updatedAt: $version,
            );

            expect($exception->values)->toBe($values)
                ->and($exception->updatedAt)->toBe($version);
        });

        it('has a default message', function () {
            $exception = new StaleWriteException(values: [], updatedAt: '');

            expect($exception->getMessage())->toBe('Settings were modified by someone else.');
        });

        it('supports empty values when row vanished', function () {
            $exception = new StaleWriteException(values: [], updatedAt: '');

            expect($exception->values)->toBe([])
                ->and($exception->updatedAt)->toBe('');
        });
    });
});
