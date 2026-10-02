<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Services;

use BAGArt\TelegramBotManagement\Exceptions\IdentityException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Canonical telegram ↔ platform-user identity link (D7/D9, ADR-001):
 * users.telegram_id is the subject reference for every permission decision.
 *
 * The `users` table belongs to the host application, so the service works
 * through the query builder on the table contract instead of a package model.
 * Provisioned accounts are telegram-first: no email, no password until a
 * recovery channel is added (docs/questions/telegram-first-user-provisioning.md).
 */
final class TelegramIdentityService
{
    /**
     * Linked platform user id for a telegram id, or null when none exists.
     */
    public function resolveByTelegramId(int $telegramId): ?int
    {
        $row = DB::table('users')->where('telegram_id', $telegramId)->first();

        return $row === null ? null : (int) $row->id;
    }

    /**
     * Whether the platform user exists at all (linked or not). The users
     * table belongs to the host, so existence checks go through the same
     * query-builder contract as the rest of this service.
     */
    public function userExists(int $userId): bool
    {
        return DB::table('users')->where('id', $userId)->exists();
    }

    /**
     * Linked telegram id for a platform user, or null when unlinked.
     */
    public function telegramIdForUser(int $userId): ?int
    {
        $row = DB::table('users')->where('id', $userId)->first();

        if ($row === null) {
            return null;
        }

        return $row->telegram_id === null ? null : (int) $row->telegram_id;
    }

    /**
     * Find-or-create the platform user for a telegram id (login/invite path).
     * Race-safe against the unique telegram_id index: a losing concurrent
     * insert re-selects instead of failing.
     *
     * @param string $name display name taken from the Telegram profile
     */
    public function provision(int $telegramId, string $name): int
    {
        $existing = $this->resolveByTelegramId($telegramId);
        if ($existing !== null) {
            return $existing;
        }

        try {
            return (int) DB::table('users')->insertGetId([
                'name' => $name !== '' ? $name : 'tg_'.$telegramId,
                'telegram_id' => $telegramId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Unique violation on telegram_id (concurrent provision) wins the race:
            $raced = $this->resolveByTelegramId($telegramId);
            if ($raced !== null) {
                return $raced;
            }

            throw $e;
        }
    }

    /**
     * Find-or-create by telegram id (thin alias used by the login confirm).
     *
     * @param string $name display name taken from the Telegram profile
     */
    public function linkOrProvision(int $telegramId, string $name): int
    {
        return $this->provision($telegramId, $name);
    }

    /**
     * Attach a telegram id to an existing platform user (link flow).
     *
     * @throws IdentityException when the user is unknown, the telegram id is
     *                           owned by another user, or the user is already
     *                           linked to a different telegram id
     */
    public function link(int $userId, int $telegramId): void
    {
        $user = DB::table('users')->where('id', $userId)->first();
        if ($user === null) {
            throw IdentityException::userNotFound($userId);
        }

        if ($user->telegram_id !== null && (int) $user->telegram_id !== $telegramId) {
            throw IdentityException::alreadyLinkedToDifferentTelegram($userId, (int) $user->telegram_id);
        }

        $owner = DB::table('users')
            ->where('telegram_id', $telegramId)
            ->where('id', '!=', $userId)
            ->first();
        if ($owner !== null) {
            throw IdentityException::telegramAlreadyLinked($telegramId, (int) $owner->id);
        }

        if ($user->telegram_id === null) {
            DB::table('users')->where('id', $userId)->update([
                'telegram_id' => $telegramId,
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Detach the telegram id. Refuses when the account has no recovery email:
     * without telegram AND without email the user could never get back in.
     *
     * @throws IdentityException when the user is unknown or has no recovery email
     */
    public function unlink(int $userId): void
    {
        $user = DB::table('users')->where('id', $userId)->first();
        if ($user === null) {
            throw IdentityException::userNotFound($userId);
        }

        if ($user->telegram_id === null) {
            return;
        }

        if ($user->email === null) {
            throw IdentityException::recoveryChannelRequired($userId);
        }

        DB::table('users')->where('id', $userId)->update([
            'telegram_id' => null,
            'updated_at' => now(),
        ]);
    }
}
