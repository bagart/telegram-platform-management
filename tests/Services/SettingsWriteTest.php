<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\Wrappers\ASKCacheWrapper;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramBot\Modules\TgModuleRegistry;
use BAGArt\TelegramBotManagement\Exceptions\StaleWriteException;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Models\TgModuleEnablement;
use BAGArt\TelegramBotManagement\Services\TgModuleEnablementService;
use Illuminate\Support\Facades\DB;

/**
 * Task 05 / §8.6 single-writer rule: SettingsWriteTest backend half (§21.1).
 * Every mutation of tg_module_enablements goes through the guarded writer.
 */
const WRITER_MODULE = 'example';

function writerService(): TgModuleEnablementService
{
    return new TgModuleEnablementService(
        moduleRegistry: app(TgModuleRegistry::class),
        cache: app(ASKCacheWrapper::class),
        ttlSeconds: 300,
    );
}

beforeEach(function () {
    config(['cache.default' => 'array']);
    config(['tg_modules.enablement_driver' => 'legacy']);
    app(TgModuleRegistry::class)->add(
        new TgModuleDescriptor(WRITER_MODULE, 'Example', '1.0.0', defaultEnabled: true),
    );
});

describe('happy path & scope targeting', function () {
    it('persists a chat-level settings map and reads back the row-level meta', function () {
        TgBot::create(['bot_id' => 'bot1', 'token' => 't:1']);

        $svc = writerService();
        $svc->setSettings(WRITER_MODULE, 'bot1', 100, ['link_flood' => true, 'links_max' => 5]);

        expect($svc->settingsWithMeta(WRITER_MODULE, 'bot1', 100)['values'])
            ->toBe(['link_flood' => true, 'links_max' => 5])
            ->and(TgModuleEnablement::query()->count())->toBe(1);
    });

    it('round-trips platform-level writes ($botId = null)', function () {
        $svc = writerService();

        $svc->setEnabled(WRITER_MODULE, null, null, false);
        $svc->setSettings(WRITER_MODULE, null, null, ['platform_key' => 'v']);

        $row = TgModuleEnablement::query()->whereNull('bot_id')->sole();
        expect($row->is_enabled)->toBeFalse()
            ->and($row->module_settings)->toBe(['platform_key' => 'v'])
            ->and($svc->settingsWithMeta(WRITER_MODULE, null, null)['values'])->toBe(['platform_key' => 'v']);
    });

    it('round-trips bot-level writes (chat_id = null)', function () {
        TgBot::create(['bot_id' => 'bot1', 'token' => 't:1']);

        writerService()->setEnabled(WRITER_MODULE, 'bot1', null, false);

        expect(TgModuleEnablement::query()->where('bot_id', 'bot1')->whereNull('chat_id')->sole()->is_enabled)
            ->toBeFalse();
    });

    it('uses REPLACE semantics for the stored map', function () {
        TgBot::create(['bot_id' => 'bot1', 'token' => 't:1']);
        TgModuleEnablement::factory()->forChat('bot1', 100)->create([
            'module_id' => WRITER_MODULE,
            'module_settings' => ['old' => 1, 'keep' => 2],
        ]);

        writerService()->setSettings(WRITER_MODULE, 'bot1', 100, ['new' => 3]);

        expect(writerService()->settingsWithMeta(WRITER_MODULE, 'bot1', 100)['values'])->toBe(['new' => 3]);
    });

    it('renders the version token per §27.6 #5 (ISO-8601 UTC microseconds + Z)', function () {
        $meta = writerService()->settingsWithMeta(WRITER_MODULE, null, null);
        expect($meta)->toBe(['values' => [], 'updatedAt' => '']);

        writerService()->setSettings(WRITER_MODULE, null, null, []);

        $token = writerService()->settingsWithMeta(WRITER_MODULE, null, null)['updatedAt'];
        expect($token)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');
    });
});

describe('CAS ladder (§13.4bis)', function () {
    beforeEach(function () {
        TgBot::create(['bot_id' => 'bot1', 'token' => 't:1']);
        TgModuleEnablement::factory()->forChat('bot1', 100)->create([
            'module_id' => WRITER_MODULE,
            'module_settings' => ['a' => 1],
        ]);
        // Ensure the seeded row and the write land on distinct wall-clock seconds.
        TgModuleEnablement::query()->update(['updated_at' => now()->subSeconds(5)]);
    });

    it('commits when expectedVersion matches and moves the version forward', function () {
        $svc = writerService();
        $before = $svc->settingsWithMeta(WRITER_MODULE, 'bot1', 100);

        $svc->setSettings(WRITER_MODULE, 'bot1', 100, ['a' => 2], $before['updatedAt']);

        $after = $svc->settingsWithMeta(WRITER_MODULE, 'bot1', 100);
        expect($after['values'])->toBe(['a' => 2])->and($after['updatedAt'])->not->toBe($before['updatedAt']);
    });

    it('throws StaleWriteException with the authoritative payload on mismatch', function () {
        $svc = writerService();
        $current = $svc->settingsWithMeta(WRITER_MODULE, 'bot1', 100);

        try {
            $svc->setSettings(WRITER_MODULE, 'bot1', 100, ['a' => 999], '2020-01-01T00:00:00.000000Z');
            $this->fail('expected StaleWriteException');
        } catch (StaleWriteException $e) {
            expect($e->values)->toBe($current['values'])
                ->and($e->updatedAt)->toBe($current['updatedAt'])
                ->and(TgModuleEnablement::query()->first()->module_settings)->toBe(['a' => 1]);
        }
    });

    it('reports an empty authoritative snapshot when the row vanished', function () {
        try {
            // Scope never seeded by the beforeEach — no row exists at all.
            writerService()->setSettings(WRITER_MODULE, 'bot1', 999, [], '2020-01-01T00:00:00.000000Z');
            $this->fail('expected StaleWriteException');
        } catch (StaleWriteException $e) {
            expect($e->values)->toBe([])->and($e->updatedAt)->toBe('');
        }
    });
});

describe('NULL-distinct index defense (race damage model)', function () {
    it('produces exactly one row per logical scope even with pre-existing duplicates', function () {
        // Simulate two concurrent platform-scope first-writes that slipped
        // past the unique index via NULL-distinct semantics (the only scope
        // pair the legacy index cannot protect is the all-NULL one).
        $first = TgModuleEnablement::factory()->platform()->create([
            'module_id' => WRITER_MODULE, 'module_settings' => ['from_first' => 1],
        ]);
        $second = new TgModuleEnablement([
            'bot_id' => null, 'chat_id' => null, 'module_id' => WRITER_MODULE,
            'is_enabled' => false, 'module_settings' => ['from_second' => 2],
        ]);
        // Distinct updated_at makes "newest wins" deterministic despite the
        // second-precision timestampsTz column.
        $second->forceFill(['updated_at' => now()->addSeconds(10)])->save();
        expect(TgModuleEnablement::query()->count())->toBe(2);

        writerService()->setEnabled(WRITER_MODULE, null, null, true);

        $row = TgModuleEnablement::query()->sole();
        expect(TgModuleEnablement::query()->count())->toBe(1)
            ->and($row->is_enabled)->toBeTrue()
            ->and($row->id)->toBe($second->id)
            ->and($row->module_settings)->toBe(['from_first' => 1, 'from_second' => 2]);
    });
});

describe('invalidation & epoch hook', function () {
    it('makes refresh() invalidation visible on the next read', function () {
        TgBot::create(['bot_id' => 'bot1', 'token' => 't:1']);

        $reader = writerService(); // simulates another process's warm cache
        $writer = writerService();

        // Prime the reader's cache while no explicit row exists yet.
        expect($reader->isEnabled(WRITER_MODULE, 'bot1', 100))->toBeTrue();

        $writer->setEnabled(WRITER_MODULE, 'bot1', 100, false);
        expect($reader->isEnabled(WRITER_MODULE, 'bot1', 100))->toBeTrue(); // stale in-memory

        $reader->refresh('bot1', 100);

        expect($reader->isEnabled(WRITER_MODULE, 'bot1', 100))->toBeFalse();
    });

    it('invokes the epoch bumper exactly once per write with write coordinates', function () {
        TgBot::create(['bot_id' => 'bot1', 'token' => 't:1']);

        $calls = [];
        $svc = writerService();
        $svc->setEpochBumper(function (string $moduleId, ?string $botId, ?int $chatId) use (&$calls): void {
            $calls[] = [$moduleId, $botId, $chatId];
        });

        $svc->setEnabled(WRITER_MODULE, 'bot1', 100, true);
        $svc->setSettings(WRITER_MODULE, null, null, []);
        $svc->setSettings(WRITER_MODULE, 'bot1', 100, ['x' => 1], $svc->settingsWithMeta(WRITER_MODULE, 'bot1', 100)['updatedAt']);

        expect($calls)->toBe([
            [WRITER_MODULE, 'bot1', 100],
            [WRITER_MODULE, null, null],
            [WRITER_MODULE, 'bot1', 100],
        ]);
    });

    it('leaves sibling chat keys TTL-stale on bot-level writes but never consumes them for mutations', function () {
        TgBot::create(['bot_id' => 'bot1', 'token' => 't:1']);

        $reader = writerService();
        $reader->isEnabled(WRITER_MODULE, 'bot1', 100); // prime (bot1, 100) decision map

        writerService()->setEnabled(WRITER_MODULE, 'bot1', null, false); // bot-level write

        expect($reader->isEnabled(WRITER_MODULE, 'bot1', 100))->toBeTrue() // documented ≤ TTL staleness
            ->and((bool)DB::table('tg_module_enablements')->where('bot_id', 'bot1')->whereNull('chat_id')->value('is_enabled'))
            ->toBeFalse(); // mutation path itself always sees fresh state
    });
});

describe('single-writer grep gate (acceptance criterion)', function () {
    it('finds no enablement-row writes outside the service within management-lib', function () {
        $srcRoot = dirname(__DIR__, 2).'/src';
        $forbidden = '/TgModuleEnablement\s*::\s*query\(\)\s*->\s*(updateOrCreate|firstOrNew|create|insert|upsert|update|delete)\s*\(/i';
        $violations = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', (string)$file);
            $relative = substr($path, strlen(str_replace('\\', '/', $srcRoot)) + 1);
            if (str_contains($relative, 'Services/TgModuleEnablementService.php')) {
                continue;
            }

            if (preg_match($forbidden, file_get_contents($path)) === 1) {
                $violations[] = $relative;
            }
        }

        expect($violations)->toBe([]);
    });
});

describe('CLI migrated onto the writer (single-writer rule)', function () {
    it('writes platform-level toggles via setEnabled ($botId = null)', function () {
        $exit = Artisan::call('tg:module:disable', ['module' => WRITER_MODULE]);

        expect($exit)->toBe(0)
            ->and(TgModuleEnablement::query()->whereNull('bot_id')->whereNull('chat_id')->sole()->is_enabled)
            ->toBeFalse();
    });

    it('writes chat-level toggles via setEnabled', function () {
        TgBot::create(['bot_id' => 'bot1', 'token' => 't:1']);

        Artisan::call('tg:module:enable', ['module' => WRITER_MODULE, '--bot' => 'bot1', '--chat' => '100']);

        expect(TgModuleEnablement::query()->where('bot_id', 'bot1')->where('chat_id', 100)->sole()->is_enabled)
            ->toBeTrue();
    });
});

describe('ModuleSettingsContract write/enumeration surface (legacy driver)', function () {
    it('merges a patch into the raw scope map and removes keys passed as null', function () {
        TgBot::create(['bot_id' => 'bot1', 'token' => 't:1']);
        TgModuleEnablement::factory()->forChat('bot1', 100)->create([
            'module_id' => WRITER_MODULE,
            'module_settings' => ['keep' => 1, 'stale' => 2],
        ]);

        $svc = writerService();
        $svc->patchSettings(WRITER_MODULE, 'bot1', 100, ['keep' => 9, 'fresh' => 3, 'stale' => null]);

        expect($svc->settingsWithMeta(WRITER_MODULE, 'bot1', 100)['values'])
            ->toBe(['keep' => 9, 'fresh' => 3])
            ->and(TgModuleEnablement::query()->count())->toBe(1);
    });

    it('creates the scope row on a patch into a scope with no row yet', function () {
        TgBot::create(['bot_id' => 'bot1', 'token' => 't:1']);

        writerService()->patchSettings(WRITER_MODULE, 'bot1', null, ['first' => true]);

        expect(TgModuleEnablement::query()->where('bot_id', 'bot1')->whereNull('chat_id')->sole()->module_settings)
            ->toBe(['first' => true]);
    });

    it('routes the reserved enabled key to chat-scope enablement instead of settings', function () {
        TgBot::create(['bot_id' => 'bot1', 'token' => 't:1']);

        $svc = writerService();
        $svc->patchSettings(WRITER_MODULE, 'bot1', 100, ['enabled' => false, 'strictness' => 2]);

        $chatRow = TgModuleEnablement::query()->where('bot_id', 'bot1')->where('chat_id', 100)->sole();
        expect($chatRow->is_enabled)->toBeFalse()
            ->and($chatRow->module_settings)->toBe(['strictness' => 2])
            ->and(TgModuleEnablement::query()->whereNull('chat_id')->count())->toBe(0);

        $svc->patchSettings(WRITER_MODULE, 'bot1', 100, ['enabled' => true]);
        expect(TgModuleEnablement::query()->where('chat_id', 100)->sole()->is_enabled)->toBeTrue();
    });

    it('routes the reserved enabled key to bot-scope enablement when chatId is null', function () {
        TgBot::create(['bot_id' => 'bot1', 'token' => 't:1']);

        $svc = writerService();
        $svc->patchSettings(WRITER_MODULE, 'bot1', null, ['enabled' => false]);

        expect(TgModuleEnablement::query()->where('bot_id', 'bot1')->whereNull('chat_id')->sole()->is_enabled)
            ->toBeFalse()
            ->and($svc->settingsWithMeta(WRITER_MODULE, 'bot1', null)['values'])->toBe([]);
    });

    it('enumerates explicit per-scope maps: chat scopes newest first, bot scope last', function () {
        TgBot::create(['bot_id' => 'bot1', 'token' => 't:1']);
        TgModuleEnablement::factory()->forChat('bot1', 100)->create([
            'module_id' => WRITER_MODULE, 'module_settings' => ['from' => 100],
        ]);
        TgModuleEnablement::factory()->forChat('bot1', 250)->create([
            'module_id' => WRITER_MODULE, 'module_settings' => ['from' => 250],
        ]);
        TgModuleEnablement::factory()->forBot('bot1')->create([
            'module_id' => WRITER_MODULE, 'module_settings' => ['scope' => 'bot'],
        ]);
        TgModuleEnablement::factory()->forChat('bot1', 999)->create([
            'module_id' => 'other_module', 'module_settings' => ['ignored' => true],
        ]);

        $expected = [
            ['botId' => 'bot1', 'chatId' => 250, 'settings' => ['from' => 250]],
            ['botId' => 'bot1', 'chatId' => 100, 'settings' => ['from' => 100]],
            ['botId' => 'bot1', 'chatId' => null, 'settings' => ['scope' => 'bot']],
        ];

        expect(writerService()->chatsWithSettings(WRITER_MODULE))->toBe($expected)
            ->and(writerService()->chatsWithSettings(WRITER_MODULE, 'bot1'))->toBe($expected)
            ->and(writerService()->chatsWithSettings(WRITER_MODULE, 'other_bot'))->toBe([]);
    });

    it('reads the bot scope without chat rows and lets a chat override win', function () {
        TgBot::create(['bot_id' => 'bot1', 'token' => 't:1']);
        TgModuleEnablement::factory()->platform()->create([
            'module_id' => WRITER_MODULE,
            'module_settings' => ['shared' => 'platform', 'platform_only' => 1],
        ]);
        TgModuleEnablement::factory()->forBot('bot1')->create([
            'module_id' => WRITER_MODULE,
            'module_settings' => ['shared' => 'bot', 'bot_only' => 2],
        ]);
        TgModuleEnablement::factory()->forChat('bot1', 100)->create([
            'module_id' => WRITER_MODULE,
            'module_settings' => ['shared' => 'chat', 'chat_only' => 3],
        ]);

        $svc = writerService();

        expect($svc->settingsFor(WRITER_MODULE, 'bot1'))
            ->toBe(['shared' => 'bot', 'platform_only' => 1, 'bot_only' => 2])
            ->and($svc->settingsFor(WRITER_MODULE, 'bot1', 100))
            ->toBe([
                'shared' => 'chat',
                'platform_only' => 1,
                'bot_only' => 2,
                'chat_only' => 3,
            ]);
    });
});

describe('one-shot dedupe migration (§19.2)', function () {
    it('collapses legacy duplicate rows and keeps the newest per logical scope', function () {
        // Platform-scope duplicates: the only all-NULL logical key pair the
        // legacy unique index cannot protect.
        $first = TgModuleEnablement::factory()->platform()->create([
            'module_id' => WRITER_MODULE, 'module_settings' => ['legacy_a' => 1],
        ]);
        $second = TgModuleEnablement::factory()->platform()->create([
            'module_id' => WRITER_MODULE, 'module_settings' => ['legacy_b' => 2],
        ]);
        // Make "newest" deterministic beyond second precision.
        DB::table('tg_module_enablements')->where('id', $first->id)
            ->update(['updated_at' => now()->subSeconds(30)]);
        expect(TgModuleEnablement::query()->whereNull('bot_id')->count())->toBe(2);

        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_08_26_000001_dedupe_tg_module_enablements_null_distinct_rows.php';
        $migration->up();

        $rows = TgModuleEnablement::query()->whereNull('bot_id')->get();
        expect($rows)->toHaveCount(1)
            ->and($rows[0]->id)->toBe($second->id)
            ->and($rows[0]->module_settings)->toBe(['legacy_a' => 1, 'legacy_b' => 2]);
    });
});
