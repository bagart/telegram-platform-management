# Management — SDD

> Laravel-facing multi-bot layer. Message processing lives in telegram-bot-lib; this package owns persistence + HTTP + ops tooling.

## Model (verified 2026-09-17)

- **Tokens-in-DB:** bot tokens + secrets in `tg_bots` (`token`, `secret_token`, hidden on serialize). No .env tokens — multi-tenancy depends on it.
- **Webhooks:** `POST /tg/` (secret-header token) and `POST /tg/tg_webhook/{bot_id}` (DB token); middlewares TgIpValidator (Telegram CIDR) + TgSecretValidator (+ TgBotIdResolver). Routes live in host `routes/web.php`, never in providers.
- **CLI:** tgbm:outbound-daemon|poller|monitor|outbound-dlq|outbound-metrics|outbound-tool|migrate|init|mcp. Daemon built explicitly via `new TgOutboundDaemon(...)`.
- **MCP (tg-ops):** queue-depth, dlq-list/retry/purge (capability-guarded), outbound-metrics, bot-list (tokens hidden), daemon-status (inferred from queue+metrics — no heartbeat yet).
- **Enablement:** `TgModuleEnablement` rows back the legacy enablement driver; engine driver uses bot_module_activations instead.

## Test Coverage (verified 2026-09-21)

26 Pest test files, 200+ test cases covering all source areas: Models (5), Services (2), Commands (12), Events (1), Exceptions (1), Registries (1), Http (1), MCP (2), Processors (1). Structural tests verify readonly DTOs, contracts, relations, casts, and factory behavior. Feature tests cover command output, error paths, enablement CAS, cache invalidation, settings controller access control, MCP tool assessment branches, processor support/process/build, token resolution, bot registry lookups, and settings update optimistic concurrency. All commands have test coverage including TgBMAuditCommand, TgBMPollerCommand, TgBotManagerMigrate, and TgMcpStartCommand.

## Production Fixes (2026-09-23)

- **`TgBot::owners()`** was `belongsToMany(..., 'tg_bot_owners', 'bot_id', 'user_id')` — self-join on the pivot, `SQLSTATE ambiguous column` on every call. Now `hasMany(TgBotOwner::class, 'bot_id', 'bot_id')`.
- **`DaemonStatus`** read `$metrics['sent:global'] ?? "{$lastHour}:sent:global"` — key never written. Metrics are written as `"{$hour}:sent:global"` and re-keyed by `collectMetrics` as `"{$hour}:sent"`; now reads `"{$lastHour}:sent"`.
- **`TgMcpStartCommand` / `TgBotManagerMigrate`** tests called `$this->artisan(...)` while asserting on a mocked `Artisan` facade — the accessor is the Console Kernel, so the mock also swallowed the outer call. Tests call `handle()` directly.

## Rules

TgBot::$hidden preserves token secrecy through serialization. Library providers never loadRoutesFrom() for tg routes. IP allowlist middleware is mandatory on both webhook routes.

`Artisan::shouldReceive()` intercepts `$this->artisan()` — assert via `handle()` when mocking the facade. `SettingsScreenControllerTest` is HTTP feature tests (`actingAs`, nested dotted payload keys, `component(..., false)` for synthetic pages), not unit-mocked.

## Telegram-first identity & device-code auth (2026-09-25)

Phase 1 of the `management-admin-rbac` workstream (task file deleted after completion; ADRs are permanent in host `docs/tasks/adr/`). Decisions live in `docs/questions/magic-link-login-identification.md` (device-code `/login <code>`, Bot API only — no platform URL ever travels through Telegram) and `docs/questions/telegram-first-user-provisioning.md` (nullable credentials, race-safe provisioning).

- **Schema:** `users.telegram_id` (unique) is the canonical identity; `users.email`/`users.password` nullable since host migration `2026_09_25_000002`. `App\Models\User` does NOT implement `MustVerifyEmail`, so the `verified` middleware needs no change for telegram-first accounts.
- **`TelegramIdentityService` (this package):** `resolveByTelegramId`, `telegramIdForUser`, `provision` (race-safe on the unique `telegram_id` index), `link` / `linkOrProvision`, `unlink` (lockout guard — refuses without a recovery email; controller maps it to HTTP 422 `recovery_required`). Failures via `IdentityException` named constructors; no method_exists/duck-typing — contract-first callers only.
- **Menu device-code store:** `tg_login_challenges` (uuid pk, `purpose` login|link, `code_hash` sha256 unique — plaintext only in the API/`/login` reply, TTL 10 min, status machine pending→confirmed→consumed|failed|expired). `LoginChallengeService` + `LoginBotLocator` (config `menu.login_bot_username`, fallback = first installed menu bot).
- **Bot side:** `LoginCommandProcessor` handles `/login <code>` — private chat only, `RateLimiter` key `tg-login-confirm|<id>` (20/min), replies never contain a URL; link purpose binds `update.from.id` to the challenge's user, login purpose provisions via `linkOrProvision`.
- **HTTP (mounted by the engine from `config/tg_modules.php` `httpRoutes` → menu `routes/auth.php`, prefix `/tg-auth`):** `POST|GET /challenge[/{id}]` (purpose derived from `Auth::id()`, throttles `tg-login-create|status`), `POST /challenge/{id}/complete` (login purpose → `Auth::loginUsingId` + `session()->regenerate()` + consume; 410 for consumed-from-another-session, 422 for link-purpose misuse), `DELETE /telegram` (auth + throttle → unlink).
- **Host surfaces:** `/login` mints the challenge server-side and renders the device-code React page (2s poll → `Auth` session → dashboard; expiry → fresh code); break-glass email/password form moved to `/login/email`. Profile page exposes `telegramId` and renders `telegram-account` (connect = challenge from an authenticated session — same machinery as link; disconnect = DELETE with the recovery-channel guard).
- **Proxy:** `BAGArt\ProxyOperations\Auth\WorkspaceResolver` previously raw-inserted a forced uuid id with NOT NULL email/password (broken under the nullable schema); it now delegates to `TelegramIdentityService::provision` and lives in its own PSR-4 file (the class used to be a side effect of loading `MagicLinkService.php`, which made container resolution fail).
- **Verification:** menu `DeviceCodeLoginTest` (17), management `TelegramIdentityServiceTest` (13), proxy `WorkspaceResolverTest` (3), host `DeviceCodeLoginPageTest` + `ProfileUpdateTest` + `UserTelegramIdTest`; root suites Unit/Feature/menu-unit/menu-feature/ProxyModule/TelegramBotManagement green (1959 passed). Known pre-existing: 13 `tests/Unit/Baseline` failures — `controls/*.php` require `__DIR__/../../vendor/autoload.php`, which resolves to `misc/BAGArt/vendor` after the package extraction; `controls/` is frozen (Phase 0), out of scope.

## Workspaces, entity registry & bot ecosystem — management-admin-rbac (2026-09-28)

Phases 2, 4, 6 of the workstream (ADRs 001–004, decisions D1–D15; task file deleted, ADRs permanent in host `docs/tasks/adr/`). Phase 1 (identity) is above.

### Entity registry (Phase 2)

- **New workspace-owned table `tg_entities`, NOT an extension of `tg_chats`.** Rationale: `tg_chats` is a frozen-schema, bot-scoped runtime projection with FK consumers — grafting workspace tenancy onto it would couple tenant lifecycle to a projection. Entities are first-class workspace objects that survive the chat-registry lifecycle. `kind = channel | chat_group | bot`; chat kinds carry `external_id`, bot kind carries `bot_id`; uniqueness is workspace-scoped.
- **`EntityLinkService`** — idempotent link, list, unlink restricted to the same workspace (cross-workspace unlink refused; covered by negative tenant-isolation tests).
- **`EntityAddVerifier`** — a single server-side `getChatAdministrators` call verifies BOTH the initiator's adminship of the target and the bot's required rights; fail-closed with typed reasons through `ChatAdministratorProbeContract` / `HttpChatAdministratorProbe` (provider binds the HTTP probe behind an `interface_exists` guard).

### Workspace services (Phase 3 side, owned here)

- **`WorkspaceAdminService`** — createWorkspace / listFor / isMember / members / addMember / changeRole / removeMember; Owner-gated, last-owner protection, defined deletion semantics.
- **`WorkspacePermissionService`** — grantT2 / denyT2 / revokeT2 / can; T1 capability → `NonGrantableCapabilityException`, T0 → `InvalidArgumentException`; scope = workspace when `chatId` is null, otherwise chat scope.
- **`WorkspaceAuditRecorder`** — workspace.created / member.added / member.removed / role.changed string operations via `AuditSinkContract` (bound-guard + fail policy). Grant changes do NOT duplicate this path — they ride `GrantCreated`/`GrantRevoked` → audit listener.

### Bot ecosystem (Phase 4, ADR-004)

- **`tg_bots` extended, no parallel registry (D12):** `class` (platform|external), `availability` (public|private), `external_token` (NEW — Laravel encrypted cast, `$hidden`; the platform `token` column is untouched).
- **`BotCatalogService`** — `addableBots` entitlement-gated (`workspaces.settings.entitlements`), `addLink` = `t.me` startgroup deep link, `connectExternal` (owner token, encrypted) and `registryOnly` (external + null token = no updates, no Bot API) both fail-closed.
- **`ConnectedBotRoster`** — workspace→bots view projected from `tg_entities` kind=bot; `tg_entities` stays the single source of truth.
- Factories: `TgBotFactory`, `TgEntityFactory` (host tests).

### tg_bot_owners retired as a rights source (D7/D11)

- Ownership for authorization = **Owner membership of a workspace that links the bot as `tg_entities` kind=bot**. Resolution: management `BotOwnershipResolver` → menu `WorkspaceBotOwnerSource` behind `BotOwnersSourceContract`; the old `EloquentBotOwnersSource` was deleted, fixtures rewritten, legacy rows proven ignored by tests.
- `SettingsScreenController` authorization moved to the same resolver. Table + `TgBotOwner` model are retained as an ownership FACT record only — never as a rights source.

### Verification (Phase 6)

Host `tests/Feature/Rbac` 63 green: cross-workspace negative matrix, 36-row tier×scope×subject deny dataset, T1-mirror revocation (zero T1 rows ever land in `access_grants`), audit spy-sink. Package suites: management 327, access 147, menu-unit 277, menu-feature 275, mafia 171, audit 103. Pre-existing unrelated failures (TelegramBotLib 45, antispam 33, host baseline Unit 13) predate this workstream.

```
workspace ──< workspace_members >── user  (subject = users.id via users.telegram_id)
    │
    ├──< tg_entities (kind=channel|chat_group|bot) ── external_id / bot_id
    │        └── kind=bot ──> tg_bots (class, availability, external_token?)
    │                              ▲
    │        BotCatalogService ────┘ (entitlement-gated add / connect / registry-only)
    └── access_grants (scope=workspace|chat, workspace_id, capability, effect)
             └── GrantCreated / GrantRevoked ──> AuditSinkContract
```

## addBot Dead Path + Audit Listener Reachability (B5, 2026-10-09) — Option A

`TelegramBotManager::addBot()` was a dead path with a live misdirecting error: the only production-adjacent creation path (`TgBotManagerInit`) called bare `TgBot::create` and fired nothing, so the audit package's `BotCreated`/`BotDeleted`/`BotTokenRotated` → `RecordModuleLifecycleEvents` mappings were unreachable in production (`BotCreated` dispatched only from `TelegramBotManager`, itself test-only; `BotDeleted`/`BotTokenRotated` never dispatched anywhere). `TgBotDbRegistry`'s error told operators to "Use TelegramBotManager" — i.e. pointed at the dead path.

- **Option A (wire) chosen:** `tgbm:init` now calls `TelegramBotManager::addBot($token)` (replacing the bare `TgBot::create`; exists-check and `extractBotId()` stay), making `bot.created` audit rows reachable through the existing listener mapping. Zero test deletions; the 3 Bot* entries remain inside W-D3's `PLATFORM_EVENT_LISTENERS` const table.
- `TgBotDbRegistry` error string made truthful: `'Use tgbm:init (TelegramBotManager::addBot) to register new Bots'` — hard-flipped in `TgBotDbRegistryTest` in the same change; `register()` not refactored so the phpstan baseline `where`×3 + `count`×1 entries stay matched.
- Wiring-regression blind spot closed: `TelegramBotManagerTest` gained an "audit listener wiring" describe block pinning the 3 Bot* → `RecordModuleLifecycleEvents@handle*` mappings via `Event::assertListening` (first such test in the repo); `TgBotManagerInitTest` asserts `BotCreated` dispatch (botId/secretToken/actor + DB row) and no-dispatch on already-exists.
- `BotDeleted`/`BotTokenRotated` still have no production dispatch sites — wiring them is a separate scope decision. `BotCatalogService::connectExternal`/`registryOnly` kept as-is (ADR-004 catalog surfaces, 14 tests green; no production caller, wiring/retiring is a product-scope decision, not a blind delete). 4 stale doc references corrected (`flow-architecture.json`, `workflow-visualizer.json`, `agent.md`, `Readme.md`) to describe the real `tgbm:init` → `addBot()` → `BotCreated` chain.
- `Order: AFTER W-D3` (digest edge over fix-impact's "independent"): both edit `AuditServiceProvider.php` in the same region (W-D3's const table vs B5's listener mapping).
