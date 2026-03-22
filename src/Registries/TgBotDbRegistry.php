<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Registries;

use BAGArt\TelegramBot\TgIntegration\BotSecretDTO;
use BAGArt\TelegramBot\Contracts\BotServices\TgBotRegistryContract;
use BAGArt\TelegramBot\Exceptions\TgTechnicalWithEntityException;
use BAGArt\TelegramBotManagement\Models\TgBot;

class TgBotDbRegistry implements TgBotRegistryContract
{
    public static function build(): self
    {


        return new self();
    }

    public function register(BotSecretDTO $bot): self
    {
        throw new TgTechnicalWithEntityException('TgBotDbRegistry::register', 'Use TelegramBotManager to register new Bots');
    }

    public function getBot(string $botId): ?BotSecretDTO
    {
        $tgBot = TgBot::where('bot_id', $botId)->first();

        return $tgBot
            ? new BotSecretDTO(
                token: $tgBot->token,
                secret: $tgBot->secret_token,
            )
            : null;
    }

    /**
     * @return \Generator|BotSecretDTO[]
     */
    public function getBotsBySecret(?string $secret): \Generator
    {
        $bots = TgBot::where('secret_token', $secret)
            ->get()
            ->map(fn (TgBot $model) => new BotSecretDTO(
                token: $model->token,
                secret: $model->secret_token,
            ))
            ->all();

        foreach ($bots as $bot) {
            yield $bot;
        }
    }

    /**
     * @return \Generator|string[]
     */
    public function getBotIdsBySecret(?string $secret): \Generator
    {
        foreach ($this->getBotsBySecret($secret) as $botSecretDTO) {
            yield $botSecretDTO->botId();
        }
    }

    public function getBotCount(): int
    {
        return TgBot::count();
    }

    public function has(string $botId): bool
    {
        return TgBot::where('bot_id', $botId)->exists();
    }

}
