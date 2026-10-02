<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Services;

use BAGArt\AsyncKernel\Wrappers\ASKCacheWrapper;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;
use BAGArt\TelegramBot\Contracts\Modules\ModuleEnablementContract;
use BAGArt\TelegramBot\Contracts\Modules\ModuleSettingsContract;
use BAGArt\TelegramBot\Modules\TgModuleRegistry;
use BAGArt\TelegramBotManagement\Events\BotModuleSettingChanged;
use BAGArt\TelegramBotManagement\Exceptions\StaleWriteException;
use BAGArt\TelegramBotManagement\Models\TgModuleEnablement;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Resolves module enablement with the inheritance chain
 * chat override → bot default → platform row → descriptor()->defaultEnabled.
 * Chat scopes of modules with descriptor()->defaultChatEnabled=false break
 * the chain: only an explicit chat row decides (Q11 chat opt-in).
 *
 * Steady-state webhooks hit only the cache (NFR-5: 0 SQL); SQL happens once
 * per (bot, chat) after TTL expiry or refresh().
 *
 * Single guarded write path for enablement/settings (menu RFC §8.6): every
 * mutation of tg_module_enablements — web controllers, CLI commands, module
 * callbacks — MUST go through setEnabled/setSettings. Authorization stays
 * with callers; the service owns scope targeting, CAS conflict detection,
 * cache invalidation and the menu epoch hook. Plugins never persist settings
 * themselves: they call these methods and read via settingsWithMeta,
 * honoring the version ladder on writes.
 */
class TgModuleEnablementService implements ModuleEnablementContract, ModuleSettingsContract
{
    private const CACHE_PREFIX = 'tg.mod.enable.';
    private const SETTINGS_CACHE_PREFIX = 'tg.mod.settings.';

    /** @var array<string, array<string, bool>> in-memory map cacheKey => [moduleId => bool] */
    private array $memory = [];

    /** @var array<string, array<string, array<string, mixed>>> in-memory settings map */
    private array $settingsMemory = [];

    /**
     * Menu epoch-bumper hook (§15.2), invoked exactly once after every
     * committed write with ($moduleId, $botId, $chatId). Optional so
     * management-lib does not depend on menu code; the menu provider
     * registers its INCR implementation in its own bootstrapping. The bumper
     * MUST NOT throw outward — it handles its own retries/degrade internally
     * so a successful write is never reported as failed.
     */
    private ?Closure $epochBumper = null;

    public function __construct(
        private readonly TgModuleRegistry $moduleRegistry,
        private readonly ASKCacheWrapper $cache,
        private readonly int $ttlSeconds = 300,
        private readonly ?ASKLogWrapper $logger = null,
    ) {
    }

    public function setEpochBumper(?Closure $bumper): void
    {
        $this->epochBumper = $bumper;
    }

    /**
     * Effective decision for one scope (Q11):
     * - `$chatId === null` (bot level): platform rows → bot rows →
     *   defaultEnabledOf — chat rows never contribute.
     * - chat scope, defaultChatEnabled=true: the legacy cascade unchanged
     *   (chat row → bot row → platform row → defaultEnabledOf).
     * - chat scope, defaultChatEnabled=false: opt-in only — an explicit chat
     *   row decides, otherwise false; bot/platform rows and defaultEnabled
     *   are ignored (chats stay OFF until individually opted in).
     */
    public function isEnabled(string $moduleId, string $botId, ?int $chatId = null): bool
    {
        if ($chatId !== null && $this->moduleRegistry->defaultChatEnabledOf($moduleId) === false) {
            try {
                $chatRows = $this->chatOptInDecisions($botId, $chatId);
            } catch (Throwable $e) {
                return $this->onEnablementStorageError($moduleId, $botId, $chatId, $e, chatOptIn: true);
            }

            return $chatRows[$moduleId] ?? false;
        }

        try {
            $decisions = $this->decisions($botId, $chatId);
        } catch (Throwable $e) {
            return $this->onEnablementStorageError($moduleId, $botId, $chatId, $e);
        }

        return $decisions[$moduleId]
            ?? (bool)($this->moduleRegistry->defaultEnabledOf($moduleId) ?? false);
    }

    /**
     * Fail policy on enablement-storage DB errors (Q-D2): fail-closed modules
     * are treated as disabled; the rest fall back to the descriptor default
     * (fail-open). A chat scope with defaultChatEnabled=false (Q11 chat
     * opt-in) resolves to false under BOTH policies — the chat default is
     * false, so a storage error must never flip an un-opted-in chat ON.
     * The error is logged and nothing is cached, so recovery is immediate
     * once the storage is back.
     */
    private function onEnablementStorageError(
        string $moduleId,
        string $botId,
        ?int $chatId,
        Throwable $e,
        bool $chatOptIn = false,
    ): bool {
        $this->logger?->error('Module enablement storage error, applying fail policy', [
            'moduleId' => $moduleId,
            'botId' => $botId,
            'chatId' => $chatId,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        if ($chatOptIn) {
            return false;
        }

        if ($this->moduleRegistry->failClosedOf($moduleId) === true) {
            return false;
        }

        return (bool)($this->moduleRegistry->defaultEnabledOf($moduleId) ?? false);
    }

    public function refresh(?string $botId = null, ?int $chatId = null): void
    {
        if ($botId !== null && $chatId !== null) {
            // Both cached views of this exact scope: the cascade map and the
            // chat opt-in map (defaultChatEnabled=false modules).
            foreach ([$this->cacheKey($botId, $chatId), $this->chatOptInCacheKey($botId, $chatId)] as $key) {
                unset($this->memory[$key]);
                $this->cache->delete($key);
            }
            unset($this->settingsMemory[$this->settingsCacheKey($botId, $chatId)]);
            $this->cache->delete($this->settingsCacheKey($botId, $chatId));

            return;
        }

        // Bot/platform-level writes: exact cached (bot, *) keys cannot be
        // enumerated via PSR-16, so only in-memory entries are dropped here;
        // cross-process staleness is bounded by the TTL (risk R-8), and the
        // menu epoch bump compensates at the bootstrap layer (§15.2).
        $prefix = self::CACHE_PREFIX.($botId !== null ? $botId.'.' : '');
        foreach (array_keys($this->memory) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->memory[$key]);
            }
        }

        $settingsPrefix = self::SETTINGS_CACHE_PREFIX.($botId !== null ? $botId.'.' : '');
        foreach (array_keys($this->settingsMemory) as $key) {
            if (str_starts_with($key, $settingsPrefix)) {
                unset($this->settingsMemory[$key]);
            }
        }
    }

    /**
     * Effective settings of a module in a scope, merged through the same
     * inheritance chain as enablement: platform → bot, plus the chat layer
     * when a chat scope is given (more specific keys win). `$chatId === null`
     * resolves the bot scope only — chat rows never contribute. Descriptor
     * defaults stay the caller's fallback; cached with the same TTL policy.
     *
     * @return array<string, mixed>
     */
    public function settingsFor(string $moduleId, string $botId, ?int $chatId = null): array
    {
        return $this->settingsMap($botId, $chatId)[$moduleId] ?? [];
    }

    public function setEnabled(string $moduleId, ?string $botId, ?int $chatId, bool $enabled, ?string $actorId = null, ?string $actorType = null): void
    {
        $this->upsert($moduleId, $botId, $chatId, fn (?TgModuleEnablement $row): array => [
            'is_enabled' => $enabled,
        ]);

        event(new BotModuleSettingChanged(
            moduleId: $moduleId,
            botId: $botId,
            chatId: $chatId,
            isEnabled: $enabled,
            settings: null,
            actorId: $actorId,
            actorType: $actorType,
        ));
    }

    /**
     * Persist the full settings map for a module at an exact scope — REPLACE
     * semantics: the stored map becomes exactly $settings. Partial patches
     * read-modify-write via settingsWithMeta() first (honoring the version
     * ladder when writing from a web surface).
     *
     * With $expectedVersion present the write is optimistic-concurrency
     * guarded (§13.4bis): one UPDATE … WHERE <scope> AND updated_at =
     * <expected>; zero affected rows ⇒ re-read and throw
     * StaleWriteException{values, updatedAt}. Without it the write is plain
     * last-write-wins (CLI / in-chat panel policy, §20).
     *
     * @param array<string, mixed> $settings
     *
     * @throws StaleWriteException on expectedVersion mismatch
     */
    public function setSettings(
        string $moduleId,
        ?string $botId,
        ?int $chatId,
        array $settings,
        ?string $expectedVersion = null,
        ?string $actorId = null,
        ?string $actorType = null,
    ): void {
        if ($expectedVersion === null) {
            $this->upsert($moduleId, $botId, $chatId, fn (?TgModuleEnablement $row): array => [
                'module_settings' => $settings,
            ]);

            event(new BotModuleSettingChanged(
                moduleId: $moduleId,
                botId: $botId,
                chatId: $chatId,
                isEnabled: null,
                settings: $settings,
                actorId: $actorId,
                actorType: $actorType,
            ));

            return;
        }

        $this->casUpdate($moduleId, $botId, $chatId, $settings, $expectedVersion);

        event(new BotModuleSettingChanged(
            moduleId: $moduleId,
            botId: $botId,
            chatId: $chatId,
            isEnabled: null,
            settings: $settings,
            actorId: $actorId,
            actorType: $actorType,
        ));
    }

    /**
     * Merge `$patch` into the RAW stored map at exactly one scope — LWW, no
     * CAS. A `key => null` entry removes the key so the scope inherits it
     * from a wider one again. The reserved `enabled` key never lands in
     * settings: it flips enablement at the same scope (chat-level when
     * `$chatId !== null`, bot-level otherwise) via setEnabled().
     *
     * @param array<string, mixed|null> $patch
     */
    public function patchSettings(string $moduleId, string $botId, ?int $chatId, array $patch): void
    {
        $settingsPatch = $patch;

        if (array_key_exists('enabled', $settingsPatch)) {
            $enabled = $settingsPatch['enabled'];
            unset($settingsPatch['enabled']);

            if (is_bool($enabled)) {
                $this->setEnabled($moduleId, $botId, $chatId, $enabled);
            }
        }

        if ($settingsPatch === []) {
            return;
        }

        $merged = [];
        $this->upsert($moduleId, $botId, $chatId, function (?TgModuleEnablement $row) use ($settingsPatch, &$merged): array {
            $merged = is_array($row?->module_settings) ? $row->module_settings : [];

            foreach ($settingsPatch as $key => $value) {
                if ($value === null) {
                    unset($merged[$key]);
                } else {
                    $merged[$key] = $value;
                }
            }

            return ['module_settings' => $merged];
        });

        event(new BotModuleSettingChanged(
            moduleId: $moduleId,
            botId: $botId,
            chatId: $chatId,
            isEnabled: null,
            settings: $merged,
        ));
    }

    /**
     * Row-level stored settings + version token for rebase flows — NOT the
     * merged inheritance view (the version belongs to one row). Absent row ⇒
     * {values: [], updatedAt: ''} ("nothing persisted yet"; PUT then runs
     * without expectedVersion).
     *
     * @return array{values: array<string, mixed>, updatedAt: string}
     */
    public function settingsWithMeta(string $moduleId, ?string $botId, ?int $chatId): array
    {
        $row = $this->scopedRows($moduleId, $botId, $chatId)->first();

        if ($row === null) {
            return ['values' => [], 'updatedAt' => ''];
        }

        return [
            'values' => is_array($row->module_settings) ? $row->module_settings : [],
            'updatedAt' => $this->versionToken($row),
        ];
    }

    /**
     * Explicit (non-inherited) stored settings for every scope the module
     * uses on a bot: chat scopes newest (highest chat id) first, the bot
     * scope (`chatId === null`) last. Platform rows carry no bot id and are
     * not enumerable under the contract shape; a scope without stored
     * settings is reported with an empty map.
     *
     * @return list<array{botId: string, chatId: int|null, settings: array<string, mixed>}>
     */
    public function chatsWithSettings(string $moduleId, ?string $botId = null): array
    {
        $rows = TgModuleEnablement::query()
            ->where('module_id', $moduleId)
            ->whereNotNull('bot_id')
            ->when($botId !== null, fn ($q) => $q->where('bot_id', $botId))
            ->orderBy('bot_id')
            ->get(['bot_id', 'chat_id', 'module_settings']);

        [$chatRows, $botRows] = $rows->partition(fn (TgModuleEnablement $row): bool => $row->chat_id !== null);

        return $chatRows->sortByDesc('chat_id', SORT_NUMERIC)
            ->concat($botRows)
            ->map(fn (TgModuleEnablement $row): array => [
                'botId' => (string) $row->bot_id,
                'chatId' => $row->chat_id,
                'settings' => is_array($row->module_settings) ? $row->module_settings : [],
            ])
            ->values()
            ->all();
    }

    /**
     * Upsert inside a transaction holding a scoped lock: the legacy unique
     * index (bot_id, chat_id, module_id) is NULL-distinct in every engine, so
     * plain updateOrCreate cannot serialize concurrent FIRST-writes for one
     * logical scope — the lock + application-side collapse can (and it also
     * heals duplicates created before the writer went live).
     *
     * @param callable(?TgModuleEnablement): array<string, mixed> $attributes
     */
    private function upsert(string $moduleId, ?string $botId, ?int $chatId, callable $attributes): void
    {
        DB::transaction(function () use ($moduleId, $botId, $chatId, $attributes): void {
            $rows = $this->scopedRows($moduleId, $botId, $chatId)
                ->lockForUpdate()
                ->orderBy('updated_at')
                ->get();

            // First write at this scope: explicit row with table defaults
            // (is_enabled=true), same as legacy CLI behavior.
            $row = $rows->isEmpty()
                ? new TgModuleEnablement(['bot_id' => $botId, 'chat_id' => $chatId, 'module_id' => $moduleId])
                : $this->collapseDuplicates($rows);

            $row->forceFill($attributes($row))->save();
        });

        $this->afterWrite($moduleId, $botId, $chatId);
    }

    /**
     * CAS, not read-check-write (§8.6 v2.2): the guarded statement carries
     * the whole conflict check; no lost-update window exists by construction.
     * Storage reality accepted: timestampsTz has second precision, so two
     * writes landing within the same wall-clock second are indistinguishable
     * to the version compare — admin-frequency writes make this negligible.
     */
    private function casUpdate(
        string $moduleId,
        ?string $botId,
        ?int $chatId,
        array $settings,
        string $expectedVersion,
    ): void {
        $affected = $this->scopedRows($moduleId, $botId, $chatId)
            ->where('updated_at', $this->parseVersionToken($expectedVersion))
            ->update(['module_settings' => json_encode($settings)]);

        if ($affected > 0) {
            $this->afterWrite($moduleId, $botId, $chatId);

            return;
        }

        // Zero rows: version mismatch OR the row vanished between GET and
        // PUT. Hand back the authoritative snapshot for the 409 body — a
        // read-only re-read needs no lock: any concurrent writer would have
        // to pass its own version check to change what we report.
        $current = $this->scopedRows($moduleId, $botId, $chatId)->first();

        throw new StaleWriteException(
            values: $current !== null && is_array($current->module_settings) ? $current->module_settings : [],
            updatedAt: $current !== null ? $this->versionToken($current) : '',
        );
    }

    /** Collapse same-scope duplicates (NULL-distinct index legacy): newest wins, older settings fill gaps underneath. */
    private function collapseDuplicates(\Illuminate\Support\Collection $rows): TgModuleEnablement
    {
        $keeper = $rows->last();
        $merged = [];

        foreach ($rows->take($rows->count() - 1) as $old) {
            $merged = array_merge($merged, is_array($old->module_settings) ? $old->module_settings : []);
            $old->delete();
        }

        // Keeper's own keys win; older rows only fill gaps underneath.
        $keeper->module_settings = array_merge($merged, is_array($keeper->module_settings) ? $keeper->module_settings : []);

        return $keeper;
    }

    /**
     * Rows at one logical scope; NULL columns are matched explicitly, since
     * SQL equality never matches NULL.
     *
     * @return \Illuminate\Database\Eloquent\Builder<TgModuleEnablement>
     */
    private function scopedRows(string $moduleId, ?string $botId, ?int $chatId)
    {
        return TgModuleEnablement::query()
            ->where('module_id', $moduleId)
            ->where(function ($q) use ($botId, $chatId): void {
                // NULL columns are matched explicitly — SQL equality never
                // matches NULL.
                if ($botId === null) {
                    $q->whereNull('bot_id');
                } else {
                    $q->where('bot_id', $botId);
                }

                if ($chatId === null) {
                    $q->whereNull('chat_id');
                } else {
                    $q->where('chat_id', $chatId);
                }
            });
    }

    private function afterWrite(string $moduleId, ?string $botId, ?int $chatId): void
    {
        $this->refresh($botId, $chatId);

        if ($this->epochBumper !== null) {
            ($this->epochBumper)($moduleId, $botId, $chatId);
        }
    }

    /** Version token format commitment (§27.6): ISO-8601 UTC microseconds + Z. */
    private function versionToken(TgModuleEnablement $row): string
    {
        return $row->updated_at->toISOString();
    }

    private function parseVersionToken(string $expectedVersion): \Carbon\CarbonInterface
    {
        return \Carbon\Carbon::createFromFormat('Y-m-d\TH:i:s.u\Z', $expectedVersion, 'UTC')
            ?? \Carbon\Carbon::parse($expectedVersion, 'UTC');
    }

    /**
     * Effective settings map for a bot (optionally chat) scope: platform →
     * bot, plus the chat row when a chat scope is given. `null` chat ids
     * narrow the query to `chat_id IS NULL`, so chat rows never contribute
     * to the bot-scope map.
     *
     * @return array<string, array<string, mixed>> moduleId => effective settings
     */
    private function settingsMap(string $botId, ?int $chatId): array
    {
        $cacheKey = $this->settingsCacheKey($botId, $chatId);

        if (isset($this->settingsMemory[$cacheKey])) {
            return $this->settingsMemory[$cacheKey];
        }

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $this->settingsMemory[$cacheKey] = $cached;
        }

        $rows = TgModuleEnablement::query()
            ->where(function ($query) use ($botId, $chatId): void {
                $query->where(fn ($q) => $q->where('bot_id', $botId)->where('chat_id', $chatId))
                    ->orWhere(fn ($q) => $q->where('bot_id', $botId)->whereNull('chat_id'))
                    ->orWhere(fn ($q) => $q->whereNull('bot_id'));
            })
            ->whereNotNull('module_settings')
            ->get(['bot_id', 'chat_id', 'module_id', 'module_settings']);

        $map = [];
        // Least specific first, so more specific keys overwrite
        foreach (['platform', 'bot', 'chat'] as $level) {
            foreach ($rows as $row) {
                if ($this->rowLevel($row) === $level && is_array($row->module_settings)) {
                    $map[$row->module_id] = array_merge(
                        $map[$row->module_id] ?? [],
                        $row->module_settings,
                    );
                }
            }
        }

        $this->cache->set($cacheKey, $map, $this->ttlSeconds);

        return $this->settingsMemory[$cacheKey] = $map;
    }

    /**
     * Effective decision map for (bot, chat): explicit rows at any level,
     * falling back to descriptor defaults for the rest. `$chatId === null`
     * narrows the query to `chat_id IS NULL`, so the map holds the bot-level
     * cascade only (platform → bot) — chat rows never contribute.
     *
     * @return array<string, bool>
     */
    private function decisions(string $botId, ?int $chatId): array
    {
        $cacheKey = $this->cacheKey($botId, $chatId);

        if (isset($this->memory[$cacheKey])) {
            return $this->memory[$cacheKey];
        }

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $this->memory[$cacheKey] = $cached;
        }

        $rows = TgModuleEnablement::query()
            ->where(function ($query) use ($botId, $chatId): void {
                $query->where(fn ($q) => $q->where('bot_id', $botId)->where('chat_id', $chatId))
                    ->orWhere(fn ($q) => $q->where('bot_id', $botId)->whereNull('chat_id'))
                    ->orWhere(fn ($q) => $q->whereNull('bot_id'));
            })
            ->get(['bot_id', 'chat_id', 'module_id', 'is_enabled']);

        $map = [];
        // Least specific first, so more specific rows overwrite
        foreach (['platform', 'bot', 'chat'] as $level) {
            foreach ($rows as $row) {
                if ($this->rowLevel($row) === $level) {
                    $map[$row->module_id] = $row->is_enabled;
                }
            }
        }

        $this->cache->set($cacheKey, $map, $this->ttlSeconds);

        return $this->memory[$cacheKey] = $map;
    }

    private function rowLevel(TgModuleEnablement $row): string
    {
        if ($row->bot_id === null) {
            return 'platform';
        }

        return $row->chat_id === null ? 'bot' : 'chat';
    }

    /**
     * Chat-level decision map for modules with defaultChatEnabled=false (Q11
     * chat opt-in): only explicit chat rows at this exact (bot, chat) scope
     * contribute — bot/platform rows are excluded by construction. Cached
     * under its own key so the opt-in view never collides with the cascade
     * map of the same (bot, chat), including the null-chat bot-level entry.
     *
     * @return array<string, bool> moduleId => explicit chat-row decision
     */
    private function chatOptInDecisions(string $botId, int $chatId): array
    {
        $cacheKey = $this->chatOptInCacheKey($botId, $chatId);

        if (isset($this->memory[$cacheKey])) {
            return $this->memory[$cacheKey];
        }

        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $this->memory[$cacheKey] = $cached;
        }

        $rows = TgModuleEnablement::query()
            ->where('bot_id', $botId)
            ->where('chat_id', $chatId)
            ->get(['module_id', 'is_enabled']);

        $map = [];
        foreach ($rows as $row) {
            $map[$row->module_id] = $row->is_enabled;
        }

        $this->cache->set($cacheKey, $map, $this->ttlSeconds);

        return $this->memory[$cacheKey] = $map;
    }

    /** Null chat id renders an empty suffix — same shape settingsCacheKey already uses. */
    private function cacheKey(string $botId, ?int $chatId): string
    {
        return self::CACHE_PREFIX.$botId.'.'.($chatId ?? '');
    }

    private function chatOptInCacheKey(string $botId, int $chatId): string
    {
        return self::CACHE_PREFIX.'optin.'.$botId.'.'.$chatId;
    }

    private function settingsCacheKey(string $botId, ?int $chatId): string
    {
        return self::SETTINGS_CACHE_PREFIX.$botId.'.'.($chatId ?? '');
    }
}
