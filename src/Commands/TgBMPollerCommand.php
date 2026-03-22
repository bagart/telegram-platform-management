<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Commands;

use BAGArt\AsyncKernel\AsyncKernel;
use BAGArt\AsyncKernel\Contracts\ASKSchedulerContract;
use BAGArt\AsyncKernel\Drivers\ASKFiberScheduler;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;
use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Configs\TgPollerConfig;
use BAGArt\TelegramBot\Configs\TgServiceConfig;
use BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiClientContract;
use BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiTypeDTOContract;
use BAGArt\TelegramBot\Exceptions\TgApiUserBreakException;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\UpdateTypeDTO;
use BAGArt\TelegramBotBasic\Commands\Traits\LongPollingCommandTrait;
use BAGArt\TelegramBotManagement\Models\TgBot;
use Illuminate\Console\Command;

class TgBMPollerCommand extends Command
{
    use LongPollingCommandTrait;

    protected $signature = 'tgbm:poll
                            {bot_uuid : TgBot UUID from database}
                            {--echo       : ECHO-mode(ping-pong)}
                            {--show       : Show messages}
                            {--timeout=30 : Long-polling server timeout in seconds}
                            {--limit=100  : Maximum updates per request (1–100)}
                            {--once       : Process one batch of updates and exit}';

    protected $description = 'Start the Telegram bot in long-polling mode';

    public function handle(
        TgBotApiDTOClientContract $tgDTOClient,
        TgBotApiClientContract $client,
        ASKLogWrapper $logger,
    ): int {
        $botUuid = $this->argument('bot_uuid');
        $tgBot = TgBot::find($botUuid);
        if ($tgBot === null) {
            $this->error("Bot not found: {$botUuid}");

            return self::FAILURE;
        }

        $token = $tgBot->token;
        $timeout = (int)$this->option('timeout');
        $once = $this->option('once');
        $echoMode = $this->option('echo');
        $showMode = $this->option('show');


        $asyncKernel = new AsyncKernel(logger: $logger);
        $asyncKernel->addTickable(new ASKFiberScheduler());

        $configPoller = $this->buildConfigPoller(
            token: $token,
            fn: function (
                TgApiTypeDTOContract $dto,
                TgServiceConfig $config,
                ?string $action = null,
                ?ASKSchedulerContract $scheduler = null,
            ) use (
                $tgDTOClient,
                $token,
                $echoMode,
                $showMode,
                $once,
            ): void {
                $update = $dto;
                assert($update instanceof UpdateTypeDTO);

                if ($showMode) {
                    if ($update->message) {
                        $this->line("{$update->message->chat->id}: {$update->message->text}");
                    } else {
                        $bp = 1; // @todo
                    }
                }
                if ($echoMode) {
                    if ($update->message) {
                        $sendMessageResponse = $tgDTOClient->request(
                            new TgBotConfig(token: $token),
                            new SendMessageMethodDTO(
                                chatId: $update->message->chat->id,
                                text: "echo: {$update->message->text}",
                            ),
                        );
                        assert($sendMessageResponse->ok === true);
                    } else {
                        $bp = 1; // @todo
                    }
                }

                if ($once) {
                    throw new TgApiUserBreakException('once');
                }
            },
            logger: $logger,
            pollerConfig: new TgPollerConfig(
                timeout: $timeout,
            ),
            asyncKernel: $asyncKernel,
        );

        $asyncKernel->addDaemon($configPoller);

        if ($this->botSetup !== null) {
            foreach ($this->botSetup->daemons as $daemon) {
                $asyncKernel->addDaemon($daemon);
            }
        }

        $asyncKernel->run();

        return self::SUCCESS;
    }
}
