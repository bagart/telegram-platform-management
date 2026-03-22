<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Services;

use BAGArt\TelegramBotManagement\Models\TgBot;

class TelegramBotManager
{
    public static function addBot(
        string $token,
    ): TgBot {
        $bot = new TgBot([
            'token' => $token,
        ]);
        $bot->save();

        return $bot;
    }
}
