<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Services;

use BAGArt\TelegramBotManagement\Events\BotCreated;
use BAGArt\TelegramBotManagement\Models\TgBot;

class TelegramBotManager
{
    public static function addBot(
        string $token,
        ?string $actorId = null,
        ?string $actorType = null,
    ): TgBot {
        $bot = new TgBot([
            'bot_id' => strstr($token, ':', true),
            'token' => $token,
        ]);
        $bot->save();

        event(new BotCreated(
            botId: $bot->bot_id,
            secretToken: $bot->secret_token ?? '',
            actorId: $actorId,
            actorType: $actorType,
        ));

        return $bot;
    }
}
