<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\TelegramBot\Modules\TgModuleCapability;
use BAGArt\TelegramBot\Modules\TgModuleContract;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramBot\Modules\TgModuleRegistrar;
use BAGArt\TelegramBotManagement\Enums\EntityKind;
use BAGArt\TelegramBotManagement\Enums\WorkspaceMemberStatus;
use BAGArt\TelegramBotManagement\Enums\WorkspaceRole;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Models\TgBotOwner;
use BAGArt\TelegramBotManagement\Models\TgEntity;
use BAGArt\TelegramBotManagement\Models\Workspace;
use BAGArt\TelegramBotManagement\Models\WorkspaceMember;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationReader;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationService;
use BAGArt\TelegramModuleEngine\Definition\TgModuleDefinition;
use BAGArt\TelegramModuleEngine\Events\BotModuleDisabled;
use BAGArt\TelegramModuleEngine\Events\BotModuleEnabled;
use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);
});

function activationBot(string $botId = 'bot1'): TgBot
{
    return TgBot::create(['bot_id' => $botId, 'token' => "t:{$botId}"]);
}

function activationUser(): User
{
    $user = User::create([
        'name' => 'Test User',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
    ]);
    test()->actingAs($user);

    return $user;
}

function activationWorkspace(string $slug): Workspace
{
    return Workspace::create(['name' => $slug, 'slug' => $slug]);
}

function activationLinkBot(Workspace $workspace, string $botId): void
{
    TgEntity::create([
        'workspace_id' => $workspace->id,
        'kind' => EntityKind::Bot,
        'bot_id' => $botId,
        'linked_at' => now(),
    ]);
}

function activationCreateOwner(string $botId, int $userId, WorkspaceRole $role = WorkspaceRole::Owner, WorkspaceMemberStatus $status = WorkspaceMemberStatus::Active): void
{
    $workspace = activationWorkspace('ws-'.uniqid());
    WorkspaceMember::create([
        'workspace_id' => $workspace->id,
        'user_id' => $userId,
        'role' => $role->value,
        'status' => $status->value,
    ]);
    activationLinkBot($workspace, $botId);
}

/**
 * Rebind registry/reader/service with a deterministic fixture set:
 * dep (default-off), feature (requires dep, default-off), offline
 * (platform-disabled) — so dependency blockers and disabled-module
 * listing are both reachable.
 */
function bindActivationDeps(): void
{
    $registry = new EngineModuleRegistry([
        new TgModuleDefinition(
            configKey: 'dep',
            provider: ActivationModuleFixture::class,
            descriptor: new TgModuleDescriptor(
                id: 'dep',
                name: 'Dep',
                version: '1.0.0',
                defaultEnabled: false,
                capabilities: [TgModuleCapability::Command],
            ),
            enabled: true,
        ),
        new TgModuleDefinition(
            configKey: 'feature',
            provider: ActivationModuleFixture::class,
            descriptor: new TgModuleDescriptor(
                id: 'feature',
                name: 'Feature',
                version: '2.0.0',
                requiresModules: ['dep' => '*'],
                defaultEnabled: false,
            ),
            enabled: true,
        ),
        new TgModuleDefinition(
            configKey: 'offline',
            provider: ActivationModuleFixture::class,
            descriptor: new TgModuleDescriptor(
                id: 'offline',
                name: 'Offline',
                version: '0.1.0',
                defaultEnabled: true,
            ),
            enabled: false,
        ),
    ]);
    $reader = new ModuleActivationReader(DB::connection(), $registry);
    $service = new ModuleActivationService(DB::connection(), $registry, $reader);

    app()->instance(EngineModuleRegistry::class, $registry);
    app()->instance(ModuleActivationReader::class, $reader);
    app()->instance(ModuleActivationService::class, $service);
}

describe('ModuleActivationController index', function () {
    it('renders with owned bots and every registry module including disabled ones', function () {
        $user = activationUser();
        activationBot('bot1');
        activationCreateOwner('bot1', $user->id);

        bindActivationDeps();

        $this->get('/modules')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('modules/index')
                ->has('bots', 1)
                ->where('bots.0.bot_id', 'bot1')
                ->where('selectedBotId', 'bot1')
                ->has('modules', 3)
                ->where('modules.0.id', 'dep')
                ->where('modules.0.enabled', false)
                ->where('modules.1.id', 'feature')
                ->where('modules.2.id', 'offline')
                ->where('modules.2.platformEnabled', false)
                ->where('modules.2.enabled', false)
                ->where('labels.title', 'Module Activation'));
    });

    it('filters bots by ownership', function () {
        $user = activationUser();
        activationBot('bot1');
        activationBot('bot2');
        activationCreateOwner('bot1', $user->id);

        bindActivationDeps();

        $this->get('/modules')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('modules/index')
                ->has('bots', 1)
                ->where('bots.0.bot_id', 'bot1'));
    });

    it('redirects unauthenticated visitors to login', function () {
        bindActivationDeps();

        $this->get('/modules')
            ->assertRedirect(route('login'));
    });
});

describe('ModuleActivationController writes', function () {
    it('enables a module, flips reader state and records an audit row', function () {
        $user = activationUser();
        activationBot('bot1');
        activationCreateOwner('bot1', $user->id);

        bindActivationDeps();

        $this->post('/modules/bot1/dep/enable')
            ->assertRedirect()
            ->assertSessionHas('success');

        expect(app(ModuleActivationReader::class)->isEffectivelyEnabled('bot1', 'dep'))->toBeTrue();

        $entry = DB::table('audit_entries')
            ->where('operation', 'module.runtime.enabled')
            ->where('bot_id', 'bot1')
            ->where('subject_id', 'dep')
            ->first();

        expect($entry)->not->toBeNull()
            ->and($entry->subject_type)->toBe('module')
            ->and($entry->source)->toBe('module-engine')
            ->and((string) $entry->actor_id)->toBe((string) $user->id);
    });

    it('is idempotent when the module is already enabled', function () {
        $user = activationUser();
        activationBot('bot1');
        activationCreateOwner('bot1', $user->id);

        bindActivationDeps();

        $this->post('/modules/bot1/dep/enable')->assertRedirect();
        $this->post('/modules/bot1/dep/enable')
            ->assertRedirect()
            ->assertSessionHas('success');

        expect(app(ModuleActivationReader::class)->isEffectivelyEnabled('bot1', 'dep'))->toBeTrue()
            ->and(DB::table('audit_entries')->where('operation', 'module.runtime.enabled')->count())->toBe(1);
    });

    it('disables a module and records a disabled audit row', function () {
        $user = activationUser();
        activationBot('bot1');
        activationCreateOwner('bot1', $user->id);

        bindActivationDeps();

        $this->post('/modules/bot1/dep/enable')->assertRedirect();
        $this->post('/modules/bot1/dep/disable')
            ->assertRedirect()
            ->assertSessionHas('success');

        expect(app(ModuleActivationReader::class)->isEffectivelyEnabled('bot1', 'dep'))->toBeFalse()
            ->and(DB::table('audit_entries')->where('operation', 'module.runtime.disabled')->count())->toBe(1);
    });

    it('blocks enabling a module with an unmet dependency and writes no audit row', function () {
        $user = activationUser();
        activationBot('bot1');
        activationCreateOwner('bot1', $user->id);

        bindActivationDeps();

        $this->post('/modules/bot1/feature/enable')
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'blockers'])
            ->assertJsonPath('blockers.0.module_id', 'dep');

        expect(app(ModuleActivationReader::class)->isEffectivelyEnabled('bot1', 'feature'))->toBeFalse()
            ->and(DB::table('audit_entries')->count())->toBe(0);
    });

    it('shows 403 and writes no audit row for an unowned bot', function () {
        activationUser();
        activationBot('bot1');

        bindActivationDeps();

        $this->post('/modules/bot1/dep/enable')->assertStatus(403);

        expect(DB::table('audit_entries')->count())->toBe(0);
    });

    it('shows 404 for an unknown module', function () {
        $user = activationUser();
        activationBot('bot1');
        activationCreateOwner('bot1', $user->id);

        bindActivationDeps();

        $this->post('/modules/bot1/nope/enable')->assertStatus(404);
    });

    it('denies a non-owner member of the linking workspace', function () {
        $user = activationUser();
        activationBot('bot1');
        activationCreateOwner('bot1', $user->id, WorkspaceRole::Member);

        bindActivationDeps();

        $this->post('/modules/bot1/dep/enable')->assertStatus(403);
    });

    it('denies a suspended owner of the linking workspace', function () {
        $user = activationUser();
        activationBot('bot1');
        activationCreateOwner('bot1', $user->id, WorkspaceRole::Owner, WorkspaceMemberStatus::Suspended);

        bindActivationDeps();

        $this->post('/modules/bot1/dep/enable')->assertStatus(403);
    });

    it('ignores legacy tg_bot_owners rows as a rights source', function () {
        $user = activationUser();
        activationBot('bot1');
        TgBotOwner::create(['bot_id' => 'bot1', 'user_id' => $user->id]);

        bindActivationDeps();

        $this->post('/modules/bot1/dep/enable')->assertStatus(403);
    });

    it('keeps the routing table free of drift after a web enable', function () {
        $user = activationUser();
        activationBot('bot1');
        activationCreateOwner('bot1', $user->id);

        bindActivationDeps();

        $this->post('/modules/bot1/dep/enable')->assertRedirect();

        $this->artisan('tg:modules:routes:check', ['botId' => 'bot1'])
            ->assertExitCode(0);
    });

    it('keeps the engine activation listeners wired for audit and route-cache refresh', function () {
        $events = app('events');

        expect($events->hasListeners(BotModuleEnabled::class))->toBeTrue()
            ->and($events->hasListeners(BotModuleDisabled::class))->toBeTrue();
    });
});

/**
 * Provider carrier for the registry fixtures below: satisfies
 * class-string<TgModuleContract> without booting a real module.
 */
final class ActivationModuleFixture implements TgModuleContract
{
    public static function descriptor(): TgModuleDescriptor
    {
        return new TgModuleDescriptor(
            id: 'activation-fixture',
            name: 'Activation Fixture',
            version: '1.0.0',
        );
    }

    public static function register(TgModuleRegistrar $registrar): void
    {
        // no-op
    }
}
