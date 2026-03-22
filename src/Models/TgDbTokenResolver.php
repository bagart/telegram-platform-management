<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Models;

use BAGArt\TelegramBot\Contracts\Outbound\BotTokenResolverContract;
use RuntimeException;

/**
 * Laravel implementation of {@see BotTokenResolverContract}.
 *
 * Reads token from DB via Eloquent model TgBot.
 * Bot tokens are stored in tg_bots, NOT in .env — see project conventions.
 */
final readonly class TgDbTokenResolver implements BotTokenResolverContract
{
    public function resolve(string $botId): string
    {
        $bot = TgBot::query()->where('bot_id', $botId)->first();

        if ($bot === null) {
            throw new RuntimeException("Bot token not found for botId: {$botId}");
        }

        if ($bot->token === null || $bot->token === '') {
            throw new RuntimeException("Bot token is empty for botId: {$botId}");
        }

        return $bot->token;
    }
}
