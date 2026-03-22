<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Commands;

use BAGArt\TelegramBotBasic\Commands\Traits\TokenResolverTrait;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Models\TgBotModule;
use BAGArt\TelegramBotManagement\Models\TgBotOwner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class TgBotManagerInit extends Command
{
    use TokenResolverTrait;

    protected $signature = 'tgbm:init
                            {--token=     : Telegram Bot token}
                            {--user_id= : Telegram original User ID (optional)}';

    protected $description = 'Telegram Bot Manager Setup';

    public function handle(): int
    {
        $token = $this->resolveToken();
        if ($token === null) {
            return self::FAILURE;
        }

        $userId = $this->option('user_id');

        $tgBotUuid = Uuid::uuid4()->toString();
        $tgBot = new TgBot([
            'tg_bot_uuid' => $tgBotUuid,
            'token' => $token,
        ]);

        $tgBotModule = new TgBotModule([
            'tg_bot_module_uuid' => Uuid::uuid4()->toString(),
            'tg_bot_uuid' => $tgBotUuid,
        ]);

        $tgBotOwner = null;
        if ($userId) {
            $tgBotOwner = new TgBotOwner([
                'tg_bot_owner_uuid' => Uuid::uuid4()->toString(),
                'tg_bot_uuid' => $tgBotUuid,
                'user_id' => (int) $userId,
            ]);
        }

        DB::transaction(function () use ($tgBot, $tgBotModule, $tgBotOwner) {
            $tgBot->save();
            $tgBotModule->save();
            $tgBotOwner?->save();
        });

        $this->info("Telegram Bot added: token: {$token}; owner(user_id): ".($userId ?: 'EMPTY'));

        return static::SUCCESS;
    }
}
