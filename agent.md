# Telegram Bot Management Lib — Architecture Context

We are working on `bagart/telegram-platform-management`.

This library provides multi-bot management for the Telegram Bot Platform:
DB-backed bot storage, token resolution, outbound daemon orchestration, and CLI commands.

All Laravel-facing classes use concrete dependencies injected via the container.
`TgBotSetupFactory` is NOT injected into Laravel classes — it is internal to
`TelegramBotServiceProvider` in `telegram-bot-lib`.

---

# Library's place in the ecosystem

```
Laravel App (routes, controllers)
        │
        ▼
telegram-platform-management  (commands, models, token resolver)
        │
        ▼
telegram-bot-lib  (service provider, outbound daemon, sender, webhook)
        │
        ▼
telegram-bot-lib-basic  (artisan command traits, poller, webhook setup)
        │
        ▼
async-kernel + ask-client + ask-client-redis  (queue, cache, transport)
```

---

# Models

### TgBot

Primary bot entity. Non-incrementing string PK `bot_id` (extracted from token:
everything before the first `:`).

- `bot_id` — string (20), PK
- `token` — string, hidden from serialization
- `secret_token` — string, nullable, hidden from serialization
- `owners(): BelongsToMany` — through `tg_bot_owners` pivot (bot_id ↔ user_id)

- `id` — auto-increment
- `bot_id` — FK → tg_bots.bot_id (cascade delete)
- `chat_id` — bigint
- `message_thread_id` — bigint, nullable
- Unique constraint: [bot_id, chat_id, message_thread_id]
- `bot(): BelongsTo` → TgBot

### TgBotOwner

Bot-to-user binding. Auto-increment PK.

- `id` — auto-increment
- `bot_id` — FK → tg_bots.bot_id (cascade delete)
- `user_id` — bigint
- Unique constraint: [bot_id, user_id]
- `bot(): BelongsTo` → TgBot

---

# Token Resolution

### TgDbTokenResolver

`final readonly class` implementing `BotTokenResolverContract`.

Resolves a `bot_id` to its token from the DB via `TgBot::query()`. Throws `RuntimeException` if bot not found or token
is empty.

Registered as singleton for `BotTokenResolverContract` in `TelegramBotServiceProvider`.

---

# Registry

### TgBotDbRegistry

Implements `TgBotRegistryContract`. Read-only bot registry backed by the DB.

- `register()` — always throws. Bots must be registered via `tgbm:init`, which calls `TelegramBotManager::addBot()` (persists the `TgBot` row and dispatches `BotCreated`).
- `getBot(botId)` — returns `BotSecretDTO` or null.
- `getBotsBySecret(secret)` — generator of `BotSecretDTO`.
- `getBotIdsBySecret(secret)` — generator of bot_id strings.
- `getBotCount()` — int.
- `has(botId)` — bool.

---

# Commands

### Bot Management

| Command             | Signature                       | Purpose                                         |
|---------------------|---------------------------------|-------------------------------------------------|
| TgBotManagerInit    | `tgbm:init --token= --user_id=` | Register bot in DB (extracts bot_id from token) |
| TgBotManagerMigrate | `migrate:tgbm`                  | Run library migrations                          |

### Polling & Monitoring

| Command            | Signature      | Purpose                       |
|--------------------|----------------|-------------------------------|
| TgBMPollerCommand  | `tgbm:poller`  | Multi-bot long-polling daemon |
| TgBMMonitorCommand | `tgbm:monitor` | Bot status monitor            |

### Outbound Infrastructure

| Command                  | Signature             | Purpose                                                                |
|--------------------------|-----------------------|------------------------------------------------------------------------|
| TgOutboundDaemonCommand  | `tg:outbound:daemon`  | Outbound worker daemon (single/multi mode, Redis, socket pool warm-up) |
| TgOutboundDlqCommand     | `tg:outbound:dlq`     | DLQ management (list/retry/purge)                                      |
| TgOutboundMetricsCommand | `tg:outbound:metrics` | Hourly outbound metrics viewer                                         |
| TgOutboundToolCommand    | `tg:outbound:tool`    | Queue inspection (status, bottlenecks, workers)                        |

---

# Service Provider

`TelegramBotManagementServiceProvider` registers:

- Management commands (all Artisan commands above)
- `BotTokenResolverContract` → `TgDbTokenResolver` (singleton)
- `TgBotSetupFactory` is NOT exposed — internal to `telegram-bot-lib`'s provider
- Loads migrations from `database/migrations/`
- Publishes `tg-outbound-daemon.php` config

---

# Webhook Endpoints

Routes defined in `routes/web.php` (platform root), NOT in the library:

- `POST /tg/` — token resolved from secret header (TgSecretValidatorMiddleware)
- `POST /tg/webhook/{bot_id}` — token resolved from DB (TgBotIdResolverMiddleware + TgDbTokenResolver)

Both protected by TgIpValidatorMiddleware + TgSecretValidatorMiddleware.

---

# DI Rules

- Laravel-facing classes (controllers, middlewares, commands) receive only concrete classes or registered contracts via
  constructor/method injection.
- `TgBotSetupFactory` is internal to `TelegramBotServiceProvider` — do NOT inject it into commands or middleware.
- All outbound singletons (`TgOutboundDaemon`, `TgOutboundStats`, `TgSenderContract`,
  `OutboundQueueContract`) are registered in `telegram-bot-lib`'s provider.

---

# Testing

Tests use Pest PHP with pure unit/model inspection pattern (no DB).

Existing: TgBotTest, TgDbTokenResolverTest, TgBotDbRegistryTest.
