<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Commands;

use BAGArt\TelegramBotBasic\Commands\Traits\TokenResolverTrait;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Services\TelegramBotManager;
use Illuminate\Console\Command;

/**
 * Telegram Bot Manager Setup.
 *
 * The --user_id option is a deprecated no-op: it never persisted an owner,
 * and tg_bot_owners is retired as a rights source (D7/D11). Ownership now
 * resolves from workspaces (tg_entities + workspace_members) through
 * BotOwnershipResolver. The flag is kept only for CLI compatibility.
 */
class TgBotManagerInit extends Command
{
    use TokenResolverTrait;

    protected $signature = 'tgbm:init
                            {--token=     : Telegram Bot token}
                            {--user_id= : DEPRECATED no-op — ownership resolves via workspaces}';

    protected $description = 'Telegram Bot Manager Setup';

    public function handle(): int
    {
        $token = $this->resolveToken();
        if ($token === null) {
            return self::FAILURE;
        }

        $botId = $this->extractBotId($token);
        $userId = $this->option('user_id');

        if (TgBot::where('bot_id', $botId)->exists()) {
            $this->warn("Bot {$botId} already exists. Skipping creation.");

            return static::SUCCESS;
        }

        $tgBot = TelegramBotManager::addBot($token);

        $this->info("Telegram Bot added: bot_id: {$tgBot->bot_id}; legacy --user_id (no-op): ".($userId ?: 'EMPTY'));

        return static::SUCCESS;
    }

    private function extractBotId(string $token): string
    {
        return (string)strstr($token, ':', true);
    }
}
