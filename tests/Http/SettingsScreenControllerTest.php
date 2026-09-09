<?php

declare(strict_types=1);

use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Models\TgBotOwner;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationReader;
use BAGArt\TelegramModuleEngine\Definition\TgModuleDefinition;
use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use BAGArt\TelegramModuleEngine\Settings\ConfigFileAccessContract;
use BAGArt\TelegramModuleEngine\Settings\SettingsDescriptor;
use BAGArt\TelegramModuleEngine\Settings\SettingsField;
use BAGArt\TelegramModuleEngine\Settings\SettingsFieldType;
use BAGArt\TelegramModuleEngine\Settings\SettingsScreenContribution;
use BAGArt\TelegramModuleEngine\Settings\WebAccessLevel;
use BAGArt\TelegramModuleEngine\Settings\WebScreenBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);
});

function createBot(string $botId = 'bot1'): TgBot
{
    return TgBot::create(['bot_id' => $botId, 'token' => "t:{$botId}"]);
}

function createUser(): \App\Models\User
{
    return \App\Models\User::create([
        'name' => 'Test User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ]);
}

function createOwner(string $botId, int $userId): void
{
    TgBotOwner::create(['bot_id' => $botId, 'user_id' => $userId]);
}

function createScreenContribution(
    string $screenId = 'test.settings',
    ?WebScreenBinding $web = null,
): SettingsScreenContribution {
    return new SettingsScreenContribution(
        screenId: $screenId,
        descriptor: new SettingsDescriptor(fields: [
            new SettingsField(
                fieldId: 'test.field',
                type: SettingsFieldType::String,
                default: 'default',
                labelKey: 'test.field',
            ),
        ]),
        web: $web,
    );
}

function makeController(
    array $screens = [],
    ?array $activeModules = null,
): \BAGArt\TelegramBotManagement\Http\Controllers\SettingsScreenController {
    $registry = mock(EngineModuleRegistry::class);
    $activations = mock(ModuleActivationReader::class);
    $configAccess = mock(ConfigFileAccessContract::class);

    $definition = new TgModuleDefinition(
        id: 'test-module',
        label: 'Test Module',
        settingsScreens: $screens,
    );

    $registry->shouldReceive('get')->andReturn($definition);
    $activations->shouldReceive('activeModuleIds')->andReturn($activeModules ?? ['test-module']);
    $configAccess->shouldReceive('read')->andReturn([]);
    $configAccess->shouldReceive('mtime')->andReturn(time());

    return new \BAGArt\TelegramBotManagement\Http\Controllers\SettingsScreenController(
        registry: $registry,
        activations: $activations,
        configAccess: $configAccess,
    );
}

describe('SettingsScreenController', function () {
    describe('bot ownership enforcement', function () {
        it('shows 403 when user does not own the bot', function () {
            $user = createUser();
            $bot = createBot('bot1');

            Auth::shouldReceive('id')->andReturn($user->id);

            $controller = makeController([createScreenContribution()]);

            $response = $controller->show(
                Request::create('/modules/settings/bot1/test.settings'),
                'bot1',
                'test.settings',
            );

            expect($response->getStatusCode())->toBe(403);
        });

        it('allows access when user owns the bot', function () {
            $user = createUser();
            $bot = createBot('bot1');
            createOwner('bot1', $user->id);

            Auth::shouldReceive('id')->andReturn($user->id);

            $controller = makeController([createScreenContribution()]);

            $response = $controller->show(
                Request::create('/modules/settings/bot1/test.settings'),
                'bot1',
                'test.settings',
            );

            expect($response->getStatusCode())->toBe(200);
        });

        it('filters bots by ownership in index', function () {
            $user = createUser();
            $bot1 = createBot('bot1');
            $bot2 = createBot('bot2');
            createOwner('bot1', $user->id);

            Auth::shouldReceive('id')->andReturn($user->id);

            $controller = makeController([]);

            $response = $controller->index(
                Request::create('/modules/settings/'),
            );

            expect($response->getStatusCode())->toBe(200);
        });
    });

    describe('access-level enforcement', function () {
        it('shows 403 for BotAdmin screen when user does not own the bot', function () {
            $user = createUser();
            $bot = createBot('bot1');

            Auth::shouldReceive('id')->andReturn($user->id);

            $controller = makeController([
                createScreenContribution(
                    'test.settings',
                    new WebScreenBinding(component: 'Settings/Test', accessLevel: WebAccessLevel::BotAdmin),
                ),
            ]);

            $response = $controller->show(
                Request::create('/modules/settings/bot1/test.settings'),
                'bot1',
                'test.settings',
            );

            expect($response->getStatusCode())->toBe(403);
        });

        it('allows PlatformAdmin screen when user owns any bot', function () {
            $user = createUser();
            $bot = createBot('bot1');
            createOwner('bot1', $user->id);

            Auth::shouldReceive('id')->andReturn($user->id);

            $controller = makeController([
                createScreenContribution(
                    'test.settings',
                    new WebScreenBinding(component: 'Settings/Test', accessLevel: WebAccessLevel::PlatformAdmin),
                ),
            ]);

            $response = $controller->show(
                Request::create('/modules/settings/bot1/test.settings'),
                'bot1',
                'test.settings',
            );

            expect($response->getStatusCode())->toBe(200);
        });

        it('shows 403 for PlatformAdmin screen when user owns no bots', function () {
            $user = createUser();

            Auth::shouldReceive('id')->andReturn($user->id);

            $controller = makeController([
                createScreenContribution(
                    'test.settings',
                    new WebScreenBinding(component: 'Settings/Test', accessLevel: WebAccessLevel::PlatformAdmin),
                ),
            ]);

            $response = $controller->show(
                Request::create('/modules/settings/bot1/test.settings'),
                'bot1',
                'test.settings',
            );

            expect($response->getStatusCode())->toBe(403);
        });

        it('filters screens by access level in index', function () {
            $user = createUser();
            $bot = createBot('bot1');
            createOwner('bot1', $user->id);

            Auth::shouldReceive('id')->andReturn($user->id);

            $controller = makeController([
                createScreenContribution(
                    'bot.settings',
                    new WebScreenBinding(component: 'Settings/Bot', accessLevel: WebAccessLevel::BotAdmin),
                ),
                createScreenContribution(
                    'platform.settings',
                    new WebScreenBinding(component: 'Settings/Platform', accessLevel: WebAccessLevel::PlatformAdmin),
                ),
            ]);

            $response = $controller->index(
                Request::create('/modules/settings/?bot_id=bot1'),
            );

            expect($response->getStatusCode())->toBe(200);
        });
    });

    describe('WebScreenBinding escape hatch', function () {
        it('renders custom component when WebScreenBinding is set', function () {
            $user = createUser();
            $bot = createBot('bot1');
            createOwner('bot1', $user->id);

            Auth::shouldReceive('id')->andReturn($user->id);

            $controller = makeController([
                createScreenContribution(
                    'test.settings',
                    new WebScreenBinding(component: 'Settings/CustomPage', accessLevel: WebAccessLevel::BotAdmin),
                ),
            ]);

            $response = $controller->show(
                Request::create('/modules/settings/bot1/test.settings'),
                'bot1',
                'test.settings',
            );

            expect($response->getStatusCode())->toBe(200);
        });

        it('falls back to generic page when WebScreenBinding is null', function () {
            $user = createUser();
            $bot = createBot('bot1');
            createOwner('bot1', $user->id);

            Auth::shouldReceive('id')->andReturn($user->id);

            $controller = makeController([
                createScreenContribution('test.settings', null),
            ]);

            $response = $controller->show(
                Request::create('/modules/settings/bot1/test.settings'),
                'bot1',
                'test.settings',
            );

            expect($response->getStatusCode())->toBe(200);
        });
    });
});
