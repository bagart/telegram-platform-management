<?php

declare(strict_types=1);

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiTypeDTOContract;
use BAGArt\TelegramBot\Exceptions\TgApiUserBreakException;
use BAGArt\TelegramBot\Http\Pure\TgApiResponse;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\UpdateTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\Enum\ChatPropTypeEnum;
use BAGArt\TelegramBot\Wrappers\Wrappers\TgOutputWrapper;
use BAGArt\TelegramBotManagement\Commands\Processors\BmPollerEchoUpdateProcessor;

function makeProcessor(
    bool $echoMode = false,
    bool $showMode = false,
    bool $once = false,
    bool $isStrictOrdered = false,
): BmPollerEchoUpdateProcessor {
    $dtoClient = mock(TgBotApiDTOClientContract::class);
    $output = new TgOutputWrapper(fopen('php://memory', 'rw'));
    $botConfig = new TgBotConfig(token: '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdef', botId: '123456');

    return new BmPollerEchoUpdateProcessor(
        dtoClient: $dtoClient,
        output: $output,
        botConfig: $botConfig,
        echoMode: $echoMode,
        showMode: $showMode,
        once: $once,
        isStrictOrdered: $isStrictOrdered,
    );
}

function makeUpdateMessage(int $chatId = 100, string $text = 'hello'): UpdateTypeDTO
{
    return new UpdateTypeDTO(
        updateId: 1,
        message: new MessageTypeDTO(
            messageId: 1,
            date: time(),
            chat: new ChatTypeDTO(id: (string) $chatId, type: ChatPropTypeEnum::PRIVATE),
            text: $text,
        ),
    );
}

function makeBotConfig(): TgBotConfig
{
    return new TgBotConfig(token: '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdef', botId: '123456');
}

describe('BmPollerEchoUpdateProcessor', function () {
    describe('support()', function () {
        it('returns true for UpdateTypeDTO', function () {
            $processor = makeProcessor();
            $dto = makeUpdateMessage();

            expect($processor->support($dto, makeBotConfig()))->toBeTrue();
        });

        it('returns false for non-UpdateTypeDTO', function () {
            $processor = makeProcessor();
            $dto = mock(TgApiTypeDTOContract::class);

            expect($processor->support($dto, makeBotConfig()))->toBeFalse();
        });
    });

    describe('process()', function () {
        it('echoes message back when echoMode is true', function () {
            $dtoClient = mock(TgBotApiDTOClientContract::class);
            $dtoClient->shouldReceive('request')
                ->once()
                ->with(
                    \Mockery::on(fn ($config) => $config instanceof TgBotConfig),
                    \Mockery::on(fn ($dto) => $dto instanceof SendMessageMethodDTO
                        && $dto->chatId === '100'
                        && $dto->text === 'echo: hello'),
                )
                ->andReturn(new TgApiResponse(ok: true, possibleResultTypes: [], result: null));

            $output = new TgOutputWrapper(fopen('php://memory', 'rw'));
            $processor = new BmPollerEchoUpdateProcessor(
                dtoClient: $dtoClient,
                output: $output,
                botConfig: makeBotConfig(),
                echoMode: true,
                showMode: false,
                once: false,
            );

            $processor->process(makeUpdateMessage(), makeBotConfig());
        });

        it('outputs chat:id text when showMode is true', function () {
            $dtoClient = mock(TgBotApiDTOClientContract::class);
            $outputStream = fopen('php://memory', 'rw');
            $output = new TgOutputWrapper($outputStream);

            $processor = new BmPollerEchoUpdateProcessor(
                dtoClient: $dtoClient,
                output: $output,
                botConfig: makeBotConfig(),
                echoMode: false,
                showMode: true,
                once: false,
            );

            $processor->process(makeUpdateMessage(200, 'world'), makeBotConfig());

            rewind($outputStream);
            $written = stream_get_contents($outputStream);

            expect($written)->toContain('200: world');
        });

        it('throws TgApiUserBreakException when once is true', function () {
            $processor = makeProcessor(once: true);

            $processor->process(makeUpdateMessage(), makeBotConfig());
        })->throws(TgApiUserBreakException::class, 'Manual break by User');

        it('does not echo when echoMode is false', function () {
            $dtoClient = mock(TgBotApiDTOClientContract::class);
            $dtoClient->shouldNotReceive('request');

            $processor = new BmPollerEchoUpdateProcessor(
                dtoClient: $dtoClient,
                output: new TgOutputWrapper(fopen('php://memory', 'rw')),
                botConfig: makeBotConfig(),
                echoMode: false,
                showMode: false,
                once: false,
            );

            $processor->process(makeUpdateMessage(), makeBotConfig());
        });
    });

    describe('isStrictOrdered()', function () {
        it('returns the configured value', function () {
            expect(makeProcessor(isStrictOrdered: false)->isStrictOrdered(makeUpdateMessage(), makeBotConfig()))->toBeFalse();
            expect(makeProcessor(isStrictOrdered: true)->isStrictOrdered(makeUpdateMessage(), makeBotConfig()))->toBeTrue();
        });
    });

    describe('isNeedUpdateDTO()', function () {
        it('returns false', function () {
            expect(makeProcessor()->isNeedUpdateDTO())->toBeFalse();
        });
    });

    describe('executionKey()', function () {
        it('returns null', function () {
            expect(makeProcessor()->executionKey(makeUpdateMessage()))->toBeNull();
        });
    });

    describe('build()', function () {
        it('throws LogicException', function () {
            $context = (new ReflectionClass(\BAGArt\TelegramBot\Processing\BotProcessorContext::class))
                ->newInstanceWithoutConstructor();

            BmPollerEchoUpdateProcessor::build($context);
        })->throws(LogicException::class, 'instance-registered');
    });
});
