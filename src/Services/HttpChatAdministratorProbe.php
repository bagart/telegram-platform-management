<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Services;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract;
use BAGArt\TelegramBot\TgApi\Methods\DTO\GetChatAdministratorsMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatMemberAdministratorTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatMemberOwnerTypeDTO;
use BAGArt\TelegramBotManagement\Contracts\ChatAdministratorProbeContract;
use BAGArt\TelegramBotManagement\DTO\EntityAddVerification;
use BAGArt\TelegramBotManagement\Enums\BotClass;
use BAGArt\TelegramBotManagement\Models\TgBot;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telegram-backed implementation of the entity-add probe (ADR-002 SD2).
 *
 * One getChatAdministrators call answers both questions: the initiator is in
 * the admin list, and the bot itself (bot_id == Telegram bot user id — see
 * TelegramBotManager::addBot) is an administrator of the chat. Transport or
 * API failures fail closed with reason `probe_failed`; the raw token is
 * never logged or included in reasons.
 */
final class HttpChatAdministratorProbe implements ChatAdministratorProbeContract
{
    public function __construct(
        private readonly TgBotApiDTOClientContract $api,
    ) {
    }

    public function verify(string $botId, int $chatId, int $initiatorTgId): EntityAddVerification
    {
        $bot = TgBot::query()->find($botId);
        if ($bot === null) {
            return $this->denied('bot_not_found');
        }

        $token = $bot->class === BotClass::External
            ? ($bot->external_token ?? '')
            : $bot->token;
        if ($token === '') {
            return $this->denied('missing_token');
        }

        $response = $this->fetchAdministrators($botId, $chatId, $token);
        if ($response === null) {
            return $this->denied('probe_failed');
        }

        $initiatorIsAdmin = false;
        $botHasRequiredRights = false;

        foreach ($response as $member) {
            $userId = (int) $member->user->id;
            if ($userId === $initiatorTgId) {
                $initiatorIsAdmin = true;
            }

            if ($member->user->isBot && $userId === (int) $botId) {
                $botHasRequiredRights = true;
            }
        }

        if (! $initiatorIsAdmin) {
            return new EntityAddVerification(false, false, $botHasRequiredRights, 'initiator_not_admin');
        }

        if (! $botHasRequiredRights) {
            return new EntityAddVerification(false, true, false, 'bot_not_admin');
        }

        return new EntityAddVerification(true, true, true, null);
    }

    /**
     * @return list<ChatMemberOwnerTypeDTO|ChatMemberAdministratorTypeDTO>|null
     */
    private function fetchAdministrators(string $botId, int $chatId, string $token): ?array
    {
        try {
            $response = $this->api->request(
                new TgBotConfig(token: $token, botId: $botId),
                new GetChatAdministratorsMethodDTO(chatId: (string) $chatId),
            );
        } catch (Throwable $e) {
            Log::warning('tg-management: getChatAdministrators probe failed', [
                'bot_id' => $botId,
                'chat_id' => $chatId,
                'exception' => $e::class,
            ]);

            return null;
        }

        if (! $response->ok || ! is_array($response->result)) {
            return null;
        }

        $members = [];
        foreach ($response->result as $member) {
            if ($member instanceof ChatMemberOwnerTypeDTO || $member instanceof ChatMemberAdministratorTypeDTO) {
                $members[] = $member;
            }
        }

        return $members;
    }

    private function denied(string $reason): EntityAddVerification
    {
        return new EntityAddVerification(false, false, false, $reason);
    }
}
