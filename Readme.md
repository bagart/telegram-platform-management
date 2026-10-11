# BAGArt/TelegramBotManagement

Laravel-facing multi-bot management layer. Bot tokens in DB, webhook routing, module enablement, outbound daemon orchestration, and MCP ops tools.

---

## Structure

```
src/
├── TelegramBotManagementServiceProvider.php  — registers commands, singletons, config, migrations
├── Models/
│   ├── TgBot.php                 — string PK (bot_id), token+secret hidden on serialize
│   ├── TgBotOwner.php            — bot-to-user pivot (BelongsToMany)
│   ├── TgChat.php                — platform chat registry (composite PK: bot_id+chat_id)
│   ├── TgDbTokenResolver.php     — BotTokenResolverContract (readonly, final)
│   └── TgModuleEnablement.php    — 3-tier enablement (platform/bot/chat), UUID PK, CAS
├── Services/
│   ├── TelegramBotManager.php    — static addBot() factory (called by tgbm:init), fires BotCreated event
│   └── TgModuleEnablementService.php — guarded writer: 3-tier inheritance, 2-layer cache, CAS
├── Registries/
│   └── TgBotDbRegistry.php       — TgBotRegistryContract (read-only, register() throws)
├── Commands/
│   ├── TgBotManagerInit.php      — tgbm:init --token=
│   ├── TgBotManagerMigrate.php   — migrate:tgbm
│   ├── TgBMPollerCommand.php     — tgbm:poll (long-polling daemon)
│   ├── TgBMMonitorCommand.php    — tgbm:monitor (feed viewer)
│   ├── TgBMAuditCommand.php      — tgbm:audit (anti-pattern scanner)
│   ├── TgOutboundDaemonCommand.php — tgbm:outbound-daemon
│   ├── TgOutboundToolCommand.php — tg:outbound:tool (queue inspection)
│   ├── TgOutboundMetricsCommand.php — tg:outbound:metrics
│   ├── TgOutboundDlqCommand.php  — tg:outbound:dlq (DLQ management)
│   ├── TgModulesListCommand.php  — tg:modules:list
│   ├── TgModulesDoctorCommand.php — tg:modules:doctor
│   ├── TgModuleToggleCommand.php — abstract base for enable/disable
│   ├── TgModuleEnableCommand.php — tg:module:enable
│   ├── TgModuleDisableCommand.php — tg:module:disable
│   ├── TgMcpStartCommand.php     — tgbm:mcp
│   └── Processors/BmPollerEchoUpdateProcessor.php
├── Events/
│   ├── BotCreated.php
│   ├── BotDeleted.php
│   ├── BotTokenRotated.php
│   └── BotModuleSettingChanged.php
├── Exceptions/
│   ├── BotManagerException.php   — implements TelegramBotException
│   └── StaleWriteException.php   — CAS version mismatch (409 conflict payload)
├── Http/Controllers/
│   └── SettingsScreenController.php — generic Inertia settings renderer
├── Mcp/
│   ├── TgOpsServer.php           — #[Name('Telegram Ops')] MCP server (7 tools)
│   └── Tools/
│       ├── BotList.php           — #[IsReadOnly]
│       ├── QueueDepth.php        — #[IsReadOnly]
│       ├── OutboundMetrics.php   — #[IsReadOnly]
│       ├── DaemonStatus.php      — #[IsReadOnly]
│       ├── DlqList.php           — #[IsReadOnly]
│       ├── DlqRetry.php          — #[IsDestructive]
│       └── DlqPurge.php          — #[IsDestructive]
├── config/tg-outbound-daemon.php
├── database/migrations/ (5 files)
├── routes/web.php (settings routes)
└── lang/{en,ru,es,fr,zh}/settings.php
```

## Tests

21 test files across Models, Services, Commands, Events, Exceptions, Registries, Http, Mcp.

| Area | Files | Coverage |
|------|-------|----------|
| Models | TgBot, TgBotOwner, TgDbTokenResolver, TgChat, TgModuleEnablement | ✅ |
| Services | TelegramBotManager, SettingsWriteTest | ✅ |
| Commands | Init, OutboundDaemon/Tool/Metrics/DLQ, ModulesList/Doctor, Toggle, Monitor | ✅ |
| Events | BotCreated/Deleted/TokenRotated/ModuleSettingChanged | ✅ |
| Exceptions | BotManagerException, StaleWriteException | ✅ |
| Registries | TgBotDbRegistry | ✅ |
| Http | SettingsScreenController | ✅ |
| MCP | QueueDepth, OutboundMetrics, DlqList, DaemonStatus, BotList, DlqRetry, DlqPurge | ✅ |

### Run Tests

```bash
php artisan test --testsuite=TelegramBotManagement --compact
```

> **Note:** Full test suite bootstrap may hang at `artisan config:clear` (known issue — see platform-backlog.md). Use `--filter` for targeted runs.
