<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Commands;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract;
use BAGArt\TelegramBot\TgApi\Methods\DTO\GetUpdatesMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\UpdateTypeDTO;
use BAGArt\TelegramBotManagement\Models\TgBot;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class TgBMMonitorCommand extends Command
{
    protected $signature = 'tgbm:monitor
                            {bot_uuid : TgBot UUID from database}
                            {--limit=20   : Number of recent updates to fetch}
                            {--sleep=5    : Polling interval in seconds when using --watch}
                            {--watch      : Continuously poll and display new messages}';

    protected $description = 'Monitor the Telegram message feed';

    private bool $keepRunning = true;

    public function handle(
        TgBotApiDTOClientContract $tgDTOClient,
    ): int {
        $botUuid = $this->argument('bot_uuid');
        $tgBot = TgBot::find($botUuid);
        if ($tgBot === null) {
            $this->error("Bot not found: {$botUuid}");

            return self::FAILURE;
        }

        $token = $tgBot->token;
        $limit = (int)$this->option('limit');
        $sleep = (int)$this->option('sleep');
        $watch = (bool)$this->option('watch');

        if ($watch) {
            $this->info("Monitoring Telegram feed (sleep: {$sleep}s). Press Ctrl+C to stop.");

            $this->trap(SIGINT, function (): void {
                $this->keepRunning = false;
            });

            $offset = 0;
            while ($this->keepRunning) {
                try {
                    $offset = $this->fetchAndDisplay(
                        $tgDTOClient,
                        $token,
                        $offset,
                        $limit
                    );
                } catch (Throwable $e) {
                    $this->error("Error: {$e->getMessage()}");
                }

                sleep($sleep);
            }

            return self::SUCCESS;
        }

        try {
            $this->fetchAndDisplay($tgDTOClient, $token, 0, $limit);
        } catch (Throwable $e) {
            $this->error("Error: {$e->getMessage()}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function fetchAndDisplay(
        TgBotApiDTOClientContract $tgDTOClient,
        string $token,
        int $offset,
        int $limit,
    ): int {
        $response = $tgDTOClient->request(
            new TgBotConfig(token: $token),
            new GetUpdatesMethodDTO(
                offset: $offset,
                limit: $limit,
                timeout: 300,
            ),
        );

        $updates = $response->result;
        if (!is_array($updates)) {
            $this->line('<fg=gray>No new updates.</>');

            return $offset;
        }

        $nextOffset = $offset;
        $rows = [];

        foreach ($updates as $update) {
            assert($update instanceof UpdateTypeDTO);
            $nextOffset = max($nextOffset, $update->updateId + 1);

            if ($update->message instanceof MessageTypeDTO) {
                $msg = $update->message;
                $rows[] = [
                    $update->updateId,
                    $msg->from->username ?? $msg->from->firstName ?? 'unknown',
                    $msg->chat->id,
                    $msg->text ?? '<fg=gray>[non-text]</>',
                    Carbon::createFromTimestamp($msg->date)->format('Y-m-d H:i:s'),
                ];
            }
        }

        if ($rows !== []) {
            $this->table(['Update ID', 'From', 'Chat ID', 'Text', 'Date'], $rows);
        } else {
            $this->line('<fg=gray>No new updates.</>');
        }

        return $nextOffset;
    }
}
