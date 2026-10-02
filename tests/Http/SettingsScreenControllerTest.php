<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramBotManagement\Enums\EntityKind;
use BAGArt\TelegramBotManagement\Enums\WorkspaceMemberStatus;
use BAGArt\TelegramBotManagement\Enums\WorkspaceRole;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Models\TgBotOwner;
use BAGArt\TelegramBotManagement\Models\TgEntity;
use BAGArt\TelegramBotManagement\Models\Workspace;
use BAGArt\TelegramBotManagement\Models\WorkspaceMember;
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
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);
    $this->withoutMiddleware([
        Authenticate::class,
        EnsureEmailIsVerified::class,
    ]);
});

function createBot(string $botId = 'bot1'): TgBot
{
    return TgBot::create(['bot_id' => $botId, 'token' => "t:{$botId}"]);
}

function createUser(): User
{
    return User::create([
        'name' => 'Test User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
    ]);
}

function ownershipWorkspace(string $slug): Workspace
{
    return Workspace::create(['name' => $slug, 'slug' => $slug]);
}

function ownershipMember(
    Workspace $workspace,
    int $userId,
    WorkspaceRole $role = WorkspaceRole::Owner,
    WorkspaceMemberStatus $status = WorkspaceMemberStatus::Active,
): void {
    WorkspaceMember::create([
        'workspace_id' => $workspace->id,
        'user_id' => $userId,
        'role' => $role,
        'status' => $status,
    ]);
}

function ownershipLinkBot(Workspace $workspace, string $botId): void
{
    TgEntity::create([
        'workspace_id' => $workspace->id,
        'kind' => EntityKind::Bot,
        'bot_id' => $botId,
        'linked_at' => now(),
    ]);
}

function createOwner(string $botId, int $userId): void
{
    $workspace = ownershipWorkspace('ws-'.uniqid());
    ownershipMember($workspace, $userId);
    ownershipLinkBot($workspace, $botId);
}

function actingAsUser(): User
{
    $user = createUser();
    test()->actingAs($user);

    return $user;
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

function bindSettingsControllerDeps(
    array $screens = [],
    ?ConfigFileAccessContract $configAccess = null,
): void {
    $definition = new TgModuleDefinition(
        configKey: 'test-module',
        provider: \stdClass::class,
        descriptor: new TgModuleDescriptor(
            id: 'test-module',
            name: 'Test Module',
            version: '1.0.0',
            defaultEnabled: true,
        ),
        enabled: true,
        settingsScreens: $screens,
    );

    $registry = new EngineModuleRegistry([$definition]);
    $activations = new ModuleActivationReader(DB::connection(), $registry);

    if ($configAccess === null) {
        $configAccess = mock(ConfigFileAccessContract::class);
        $configAccess->shouldReceive('read')->andReturn([]);
        $configAccess->shouldReceive('mtime')->andReturn(time());
        $configAccess->shouldReceive('write')->andReturnNull();
    }

    app()->instance(EngineModuleRegistry::class, $registry);
    app()->instance(ModuleActivationReader::class, $activations);
    app()->instance(ConfigFileAccessContract::class, $configAccess);
}

describe('SettingsScreenController', function () {
    describe('bot ownership enforcement', function () {
        it('shows 403 when user does not own the bot', function () {
            $user = actingAsUser();
            createBot('bot1');

            bindSettingsControllerDeps([createScreenContribution()]);

            $this->get('/modules/settings/bot1/test.settings')
                ->assertStatus(403);
        });

        it('allows access when user owns the bot', function () {
            $user = actingAsUser();
            createBot('bot1');
            createOwner('bot1', $user->id);

            bindSettingsControllerDeps([createScreenContribution()]);

            $this->get('/modules/settings/bot1/test.settings')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('settings/modules/show')
                    ->where('botId', 'bot1')
                    ->where('moduleId', 'test-module')
                    ->where('screenId', 'test.settings'));
        });

        it('filters bots by ownership in index', function () {
            $user = actingAsUser();
            createBot('bot1');
            createBot('bot2');
            createOwner('bot1', $user->id);

            bindSettingsControllerDeps([]);

            $this->get('/modules/settings/')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('settings/modules/index')
                    ->has('bots', 1)
                    ->where('bots.0.bot_id', 'bot1'));
        });
    });

    describe('workspace ownership resolution (D7)', function () {
        it('denies a non-owner member of the linking workspace', function () {
            $user = actingAsUser();
            createBot('bot1');
            $workspace = ownershipWorkspace('ws-member');
            ownershipMember($workspace, $user->id, WorkspaceRole::Member);
            ownershipLinkBot($workspace, 'bot1');

            bindSettingsControllerDeps([createScreenContribution()]);

            $this->get('/modules/settings/bot1/test.settings')
                ->assertStatus(403);
        });

        it('denies an owner of a different workspace that does not link the bot', function () {
            $user = actingAsUser();
            createBot('bot1');
            createBot('bot2');
            $workspace = ownershipWorkspace('ws-other');
            ownershipMember($workspace, $user->id);
            ownershipLinkBot($workspace, 'bot2');

            bindSettingsControllerDeps([createScreenContribution()]);

            $this->get('/modules/settings/bot1/test.settings')
                ->assertStatus(403);
        });

        it('denies a suspended member of the linking workspace', function () {
            $user = actingAsUser();
            createBot('bot1');
            $workspace = ownershipWorkspace('ws-suspended');
            ownershipMember($workspace, $user->id, WorkspaceRole::Owner, WorkspaceMemberStatus::Suspended);
            ownershipLinkBot($workspace, 'bot1');

            bindSettingsControllerDeps([createScreenContribution()]);

            $this->get('/modules/settings/bot1/test.settings')
                ->assertStatus(403);
        });

        it('allows an owner whose second workspace links the bot', function () {
            $user = actingAsUser();
            createBot('bot1');
            $empty = ownershipWorkspace('ws-empty');
            ownershipMember($empty, $user->id);
            $linking = ownershipWorkspace('ws-linking');
            ownershipMember($linking, $user->id);
            ownershipLinkBot($linking, 'bot1');

            bindSettingsControllerDeps([createScreenContribution()]);

            $this->get('/modules/settings/bot1/test.settings')
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component('settings/modules/show'));
        });

        it('ignores legacy tg_bot_owners rows as a rights source', function () {
            $user = actingAsUser();
            createBot('bot1');
            TgBotOwner::create(['bot_id' => 'bot1', 'user_id' => $user->id]);

            bindSettingsControllerDeps([createScreenContribution()]);

            $this->get('/modules/settings/bot1/test.settings')
                ->assertStatus(403);
        });
    });

    describe('access-level enforcement', function () {
        it('shows 403 for BotAdmin screen when user does not own the bot', function () {
            actingAsUser();
            createBot('bot1');

            bindSettingsControllerDeps([
                createScreenContribution(
                    'test.settings',
                    new WebScreenBinding(component: 'Settings/Test', accessLevel: WebAccessLevel::BotAdmin),
                ),
            ]);

            $this->get('/modules/settings/bot1/test.settings')
                ->assertStatus(403);
        });

        it('allows PlatformAdmin screen when user owns any bot', function () {
            $user = actingAsUser();
            createBot('bot1');
            createOwner('bot1', $user->id);

            bindSettingsControllerDeps([
                createScreenContribution(
                    'test.settings',
                    new WebScreenBinding(component: 'Settings/Test', accessLevel: WebAccessLevel::PlatformAdmin),
                ),
            ]);

            $this->get('/modules/settings/bot1/test.settings')
                ->assertOk();
        });

        it('shows 403 for PlatformAdmin screen when user owns no bots', function () {
            actingAsUser();

            bindSettingsControllerDeps([
                createScreenContribution(
                    'test.settings',
                    new WebScreenBinding(component: 'Settings/Test', accessLevel: WebAccessLevel::PlatformAdmin),
                ),
            ]);

            $this->get('/modules/settings/bot1/test.settings')
                ->assertStatus(403);
        });

        it('filters screens by access level in index', function () {
            $user = actingAsUser();
            createBot('bot1');
            createOwner('bot1', $user->id);

            bindSettingsControllerDeps([
                createScreenContribution(
                    'bot.settings',
                    new WebScreenBinding(component: 'Settings/Bot', accessLevel: WebAccessLevel::BotAdmin),
                ),
                createScreenContribution(
                    'platform.settings',
                    new WebScreenBinding(component: 'Settings/Platform', accessLevel: WebAccessLevel::PlatformAdmin),
                ),
            ]);

            $this->get('/modules/settings/?bot_id=bot1')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('settings/modules/index')
                    ->has('screens', 2));
        });
    });

    describe('WebScreenBinding escape hatch', function () {
        it('renders custom component when WebScreenBinding is set', function () {
            $user = actingAsUser();
            createBot('bot1');
            createOwner('bot1', $user->id);

            bindSettingsControllerDeps([
                createScreenContribution(
                    'test.settings',
                    new WebScreenBinding(component: 'Settings/CustomPage', accessLevel: WebAccessLevel::BotAdmin),
                ),
            ]);

            $this->get('/modules/settings/bot1/test.settings')
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component('Settings/CustomPage', false));
        });

        it('falls back to generic page when WebScreenBinding is null', function () {
            $user = actingAsUser();
            createBot('bot1');
            createOwner('bot1', $user->id);

            bindSettingsControllerDeps([
                createScreenContribution('test.settings', null),
            ]);

            $this->get('/modules/settings/bot1/test.settings')
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component('settings/modules/show'));
        });
    });

    describe('update()', function () {
        it('saves valid settings successfully', function () {
            $user = actingAsUser();
            createBot('bot1');
            createOwner('bot1', $user->id);

            $configAccess = mock(ConfigFileAccessContract::class);
            $configAccess->shouldReceive('mtime')->andReturn(time(), time() + 1);
            $configAccess->shouldReceive('write')->once()->with('test-module', ['test.field' => 'new_value']);
            $configAccess->shouldReceive('read')->andReturn([]);

            bindSettingsControllerDeps([createScreenContribution()], $configAccess);

            $this->putJson('/modules/settings/bot1/test.settings', ['test' => ['field' => 'new_value']])
                ->assertOk()
                ->assertJson(['message' => 'Settings saved.']);
        });

        it('returns 409 on optimistic concurrency conflict', function () {
            $user = actingAsUser();
            createBot('bot1');
            createOwner('bot1', $user->id);

            $configAccess = mock(ConfigFileAccessContract::class);
            $configAccess->shouldReceive('mtime')->andReturn(2000);
            $configAccess->shouldReceive('read')->once()->with('test-module')->andReturn(['test.field' => 'server_value']);

            bindSettingsControllerDeps([createScreenContribution()], $configAccess);

            $this->putJson('/modules/settings/bot1/test.settings', [
                'test' => ['field' => 'new_value'],
                '_mtime' => 1000,
            ])
                ->assertStatus(409)
                ->assertJson(['message' => 'Settings were modified by someone else.']);
        });

        it('returns 422 on validation failure', function () {
            $user = actingAsUser();
            createBot('bot1');
            createOwner('bot1', $user->id);

            $configAccess = mock(ConfigFileAccessContract::class);
            $configAccess->shouldReceive('mtime')->andReturn(time());

            $descriptor = new SettingsDescriptor(fields: [
                new SettingsField(
                    fieldId: 'test.field',
                    type: SettingsFieldType::Int,
                    default: 0,
                    labelKey: 'test.field',
                    required: true,
                ),
            ]);

            $definition = new TgModuleDefinition(
                configKey: 'test-module',
                provider: \stdClass::class,
                descriptor: new TgModuleDescriptor(
                    id: 'test-module',
                    name: 'Test Module',
                    version: '1.0.0',
                    defaultEnabled: true,
                ),
                enabled: true,
                settingsScreens: [
                    new SettingsScreenContribution(
                        screenId: 'test.settings',
                        descriptor: $descriptor,
                    ),
                ],
            );

            $registry = new EngineModuleRegistry([$definition]);
            $activations = new ModuleActivationReader(DB::connection(), $registry);

            app()->instance(EngineModuleRegistry::class, $registry);
            app()->instance(ModuleActivationReader::class, $activations);
            app()->instance(ConfigFileAccessContract::class, $configAccess);

            $this->putJson('/modules/settings/bot1/test.settings', ['test' => ['field' => 'not_an_int']])
                ->assertStatus(422);
        });
    });
});
