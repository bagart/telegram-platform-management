<?php

declare(strict_types=1);

use BAGArt\TelegramBotManagement\Exceptions\IdentityException;
use BAGArt\TelegramBotManagement\Services\TelegramIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function identityFixtureUser(array $overrides = []): int
{
    return (int) DB::table('users')->insertGetId(array_merge([
        'name' => 'fixture',
        'email' => null,
        'password' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

describe('resolve & provision', function () {
    it('resolves the linked platform user for a telegram id', function () {
        $userId = identityFixtureUser(['telegram_id' => 42]);
        $service = new TelegramIdentityService();

        expect($service->resolveByTelegramId(42))->toBe($userId)
            ->and($service->resolveByTelegramId(43))->toBeNull()
            ->and($service->telegramIdForUser($userId))->toBe(42)
            ->and($service->telegramIdForUser(99999))->toBeNull();
    });

    it('provisions a telegram-first account without email or password', function () {
        $service = new TelegramIdentityService();

        $userId = $service->provision(1001, 'Alice');

        $row = DB::table('users')->where('id', $userId)->first();
        expect($row->telegram_id)->toBe(1001)
            ->and($row->name)->toBe('Alice')
            ->and($row->email)->toBeNull()
            ->and($row->password)->toBeNull();
    });

    it('is idempotent: provisioning the same telegram id returns the same user', function () {
        $service = new TelegramIdentityService();

        $first = $service->provision(1002, 'Alice');
        $second = $service->provision(1002, 'Alice Renamed');

        expect($second)->toBe($first)
            ->and(DB::table('users')->where('telegram_id', 1002)->count())->toBe(1);
    });

    it('falls back to tg_<id> when the Telegram profile provides no name', function () {
        $service = new TelegramIdentityService();

        $userId = $service->provision(1003, '');

        expect(DB::table('users')->where('id', $userId)->value('name'))->toBe('tg_1003');
    });

    it('linkOrProvision resolves existing users and creates missing ones', function () {
        $service = new TelegramIdentityService();
        $existing = identityFixtureUser(['telegram_id' => 2001]);

        expect($service->linkOrProvision(2001, 'Alice'))->toBe($existing)
            ->and($service->linkOrProvision(2002, 'Bob'))->toBeInt();
    });
});

describe('link', function () {
    it('attaches a free telegram id to an unlinked user', function () {
        $userId = identityFixtureUser();
        $service = new TelegramIdentityService();

        $service->link($userId, 3001);

        expect($service->telegramIdForUser($userId))->toBe(3001);
    });

    it('is idempotent for the same telegram id', function () {
        $userId = identityFixtureUser(['telegram_id' => 3002]);
        $service = new TelegramIdentityService();

        $service->link($userId, 3002);

        expect($service->telegramIdForUser($userId))->toBe(3002);
    });

    it('rejects a telegram id owned by another user', function () {
        $owner = identityFixtureUser(['telegram_id' => 3003]);
        $other = identityFixtureUser();
        $service = new TelegramIdentityService();

        expect(fn (): mixed => $service->link($other, 3003))
            ->toThrow(IdentityException::class)
            ->and($service->telegramIdForUser($owner))->toBe(3003);
    });

    it('requires an explicit unlink when the user is linked elsewhere', function () {
        $userId = identityFixtureUser(['telegram_id' => 3004]);
        $service = new TelegramIdentityService();

        expect(fn (): mixed => $service->link($userId, 3005))
            ->toThrow(IdentityException::class)
            ->and($service->telegramIdForUser($userId))->toBe(3004);
    });

    it('fails for an unknown user', function () {
        $service = new TelegramIdentityService();

        expect(fn (): mixed => $service->link(999999, 3006))
            ->toThrow(IdentityException::class);
    });
});

describe('unlink', function () {
    it('clears the telegram link when a recovery email exists', function () {
        $userId = identityFixtureUser([
            'telegram_id' => 4001,
            'email' => 'recovery@example.com',
        ]);
        $service = new TelegramIdentityService();

        $service->unlink($userId);

        expect($service->telegramIdForUser($userId))->toBeNull();
    });

    it('refuses an unlink that would leave the account without any recovery channel', function () {
        $userId = identityFixtureUser(['telegram_id' => 4002]);
        $service = new TelegramIdentityService();

        expect(fn (): mixed => $service->unlink($userId))
            ->toThrow(IdentityException::class)
            ->and($service->telegramIdForUser($userId))->toBe(4002);
    });

    it('is a no-op for an unlinked user and fails for an unknown user', function () {
        $unlinked = identityFixtureUser();
        $service = new TelegramIdentityService();

        $service->unlink($unlinked);

        expect(fn (): mixed => $service->unlink(999999))
            ->toThrow(IdentityException::class);
    });
});
