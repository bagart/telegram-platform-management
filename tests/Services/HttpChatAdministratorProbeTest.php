<?php

declare(strict_types=1);

use BAGArt\ASKClient\Contracts\Pipeline\ASKFutureContract;
use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiMethodDTOContract;
use BAGArt\TelegramBot\Http\Pure\TgApiResponse;
use BAGArt\TelegramBot\TgApi\Methods\DTO\GetChatAdministratorsMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatMemberAdministratorTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatMemberOwnerTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\UserTypeDTO;
use BAGArt\TelegramBotManagement\Contracts\ChatAdministratorProbeContract;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Services\HttpChatAdministratorProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Typed fake API client: replays a fixed TgApiResponse (or throws) and
 * records every request so tests can assert call counts and arguments
 * without a Mockery-generated class.
 */
final class FakeChatAdminApi implements TgBotApiDTOClientContract
{
    /**
     * @var list<array{botConfig: TgBotConfig, method: TgApiMethodDTOContract, chatId: string|null}>
     */
    public array $calls = [];

    public function __construct(
        private readonly TgApiResponse|Throwable $outcome,
    ) {
    }

    public function request(TgBotConfig $botConfig, TgApiMethodDTOContract $dto, ?int $timeout = null): TgApiResponse
    {
        $this->calls[] = [
            'botConfig' => $botConfig,
            'method' => $dto,
            'chatId' => $dto instanceof GetChatAdministratorsMethodDTO ? $dto->chatId : null,
        ];

        if ($this->outcome instanceof Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }

    public function requestAsync(TgBotConfig $botConfig, TgApiMethodDTOContract $dto, ?int $timeout = null): ASKFutureContract
    {
        throw new LogicException('requestAsync is not used by the entity-add probe.');
    }

    public function tickable(): array
    {
        return [];
    }
}

/**
 * @param  list<ChatMemberAdministratorTypeDTO|ChatMemberOwnerTypeDTO>  $members
 */
function probeAdministratorResponse(array $members): TgApiResponse
{
    return new TgApiResponse(true, [], $members);
}

function probeMember(string $userId, bool $isBot = false): ChatMemberAdministratorTypeDTO
{
    return new ChatMemberAdministratorTypeDTO(
        user: new UserTypeDTO(id: $userId, isBot: $isBot, firstName: 'Member'),
        canBeEdited: false,
        isAnonymous: false,
        canManageChat: true,
        canDeleteMessages: true,
        canManageVideoChats: false,
        canRestrictMembers: true,
        canPromoteMembers: false,
        canChangeInfo: false,
        canInviteUsers: true,
        canPostStories: false,
        canEditStories: false,
        canDeleteStories: false,
    );
}

describe('HttpChatAdministratorProbe', function () {
    it('allows when both the initiator and the bot are chat administrators', function () {
        TgBot::factory()->create(['bot_id' => '900001']);
        $api = new FakeChatAdminApi(probeAdministratorResponse([
            probeMember('555'),
            probeMember('900001', isBot: true),
        ]));

        $result = (new HttpChatAdministratorProbe($api))->verify('900001', -100900, 555);

        expect($result->allowed)->toBeTrue()
            ->and($result->initiatorIsAdmin)->toBeTrue()
            ->and($result->botHasRequiredRights)->toBeTrue()
            ->and($result->reason)->toBeNull()
            ->and($api->calls)->toHaveCount(1)
            ->and($api->calls[0]['botConfig']->botId)->toBe('900001')
            ->and($api->calls[0]['method'])->toBeInstanceOf(GetChatAdministratorsMethodDTO::class)
            ->and($api->calls[0]['chatId'])->toBe('-100900');
    });

    it('accepts the chat creator as a valid initiator', function () {
        TgBot::factory()->create(['bot_id' => '900002']);
        $api = new FakeChatAdminApi(probeAdministratorResponse([
            new ChatMemberOwnerTypeDTO(
                user: new UserTypeDTO(id: '777', isBot: false, firstName: 'Owner'),
                isAnonymous: false,
            ),
            probeMember('900002', isBot: true),
        ]));

        $result = (new HttpChatAdministratorProbe($api))->verify('900002', -100901, 777);

        expect($result->allowed)->toBeTrue()
            ->and($result->initiatorIsAdmin)->toBeTrue();
    });

    it('denies when the initiator is not an administrator', function () {
        TgBot::factory()->create(['bot_id' => '900003']);
        $api = new FakeChatAdminApi(probeAdministratorResponse([
            probeMember('900003', isBot: true),
        ]));

        $result = (new HttpChatAdministratorProbe($api))->verify('900003', -100902, 555);

        expect($result->allowed)->toBeFalse()
            ->and($result->reason)->toBe('initiator_not_admin')
            ->and($result->botHasRequiredRights)->toBeTrue();
    });

    it('denies when the bot itself is not an administrator', function () {
        TgBot::factory()->create(['bot_id' => '900004']);
        $api = new FakeChatAdminApi(probeAdministratorResponse([
            probeMember('555'),
        ]));

        $result = (new HttpChatAdministratorProbe($api))->verify('900004', -100903, 555);

        expect($result->allowed)->toBeFalse()
            ->and($result->reason)->toBe('bot_not_admin')
            ->and($result->initiatorIsAdmin)->toBeTrue();
    });

    it('fails closed when the API call throws', function () {
        TgBot::factory()->create(['bot_id' => '900005']);
        $api = new FakeChatAdminApi(new RuntimeException('Connection refused'));

        $result = (new HttpChatAdministratorProbe($api))->verify('900005', -100904, 555);

        expect($result->allowed)->toBeFalse()
            ->and($result->reason)->toBe('probe_failed')
            ->and($api->calls)->toHaveCount(1);
    });

    it('denies for a bot unknown to the platform without calling Telegram', function () {
        $api = new FakeChatAdminApi(probeAdministratorResponse([]));

        $result = (new HttpChatAdministratorProbe($api))->verify('424242424', -100905, 555);

        expect($result->allowed)->toBeFalse()
            ->and($result->reason)->toBe('bot_not_found')
            ->and($api->calls)->toBeEmpty();
    });

    it('denies a registry-only external bot (no token — no Bot API calls)', function () {
        TgBot::factory()->external()->state(['external_token' => null])->create(['bot_id' => '900006']);
        $api = new FakeChatAdminApi(probeAdministratorResponse([]));

        $result = (new HttpChatAdministratorProbe($api))->verify('900006', -100906, 555);

        expect($result->allowed)->toBeFalse()
            ->and($result->reason)->toBe('missing_token')
            ->and($api->calls)->toBeEmpty();
    });
});

describe('probe container binding', function () {
    it('resolves the Telegram-backed probe when telegram-bot-lib is installed', function () {
        expect(interface_exists(\BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract::class))
            ->toBeTrue()
            ->and(app(ChatAdministratorProbeContract::class))
            ->toBeInstanceOf(HttpChatAdministratorProbe::class);
    });
});
