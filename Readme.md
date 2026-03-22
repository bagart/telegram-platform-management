# BAGArt/TelegramBotManagement

Bot manager: storing tokens in the database, migrations, commands for managing bots.

---

## Code Coverage

> Generated: 2026-03-18 | 0 dedicated tests | ⚠️ needs tests

### Coverage by Folder

| Folder | File | Coverage | Bar |
|--------|------|----------|-----|
| **Models** | TgBot | `0.0%` | `░░░░░░░░░░░░░░░░░░░░` |
| | TgBotModule | `0.0%` | `░░░░░░░░░░░░░░░░░░░░` |
| | TgBotOwner | `0.0%` | `░░░░░░░░░░░░░░░░░░░░` |
| **Commands** | TgBotManagerInit | `0.0%` | `░░░░░░░░░░░░░░░░░░░░` |
| | TgBotManagerMigrate | `0.0%` | `░░░░░░░░░░░░░░░░░░░░` |
| | TgBMPollerCommand | `0.0%` | `░░░░░░░░░░░░░░░░░░░░` |
| | TgBMMonitorCommand | `0.0%` | `░░░░░░░░░░░░░░░░░░░░` |
| **Migrations** | 3 migration files | — | schema only |
| | TelegramBotManager | `0.0%` | `░░░░░░░░░░░░░░░░░░░░` |

### Overall

```
████████████████████  0.0%
```

> No tests written yet for this library. Priority targets:
> - `TelegramBotManager::addBot()` — static method, testable with DB
> - `TgBot` model — fillable, hidden, primary key
> - `TgBotManagerInit` — extract token validation, test UUID generation

### Files

```
misc/BAGArt/TelegramBotManagement/
├── Models/
│   ├── TgBot.php (30 lines) — token storage model
│   ├── TgBotModule.php — module config
│   └── TgBotOwner.php — owner mapping
├── Commands/
│   ├── TgBotManagerInit.php (64 lines) — init bot with token
│   ├── TgBotManagerMigrate.php — run migrations
│   ├── TgBMPollerCommand.php (96 lines) — polling from DB token
│   └── TgBMMonitorCommand.php — monitoring
├── Migrations/
│   ├── 2026_02_25_022457_create_tg_bots_table.php
│   ├── 2026_02_25_025209_create_tg_bot_modules_table.php
│   └── 2026_02_25_025215_create_tg_bot_owners_table.php
└── TelegramBotManager.php (19 lines) — static addBot()
```

### Run Tests

```bash
# No tests yet — create with:
php artisan make:test --pest TelegramBotManager/TgBotTest
php artisan make:test --pest TelegramBotManager/TelegramBotManagerTest
```
