# Management — SDD

> Laravel-facing multi-bot layer. Message processing lives in telegram-bot-lib; this package owns persistence + HTTP + ops tooling.

## Model (verified 2026-09-17)

- **Tokens-in-DB:** bot tokens + secrets in `tg_bots` (`token`, `secret_token`, hidden on serialize). No .env tokens — multi-tenancy depends on it.
- **Webhooks:** `POST /tg/` (secret-header token) and `POST /tg/tg_webhook/{bot_id}` (DB token); middlewares TgIpValidator (Telegram CIDR) + TgSecretValidator (+ TgBotIdResolver). Routes live in host `routes/web.php`, never in providers.
- **CLI:** tgbm:outbound-daemon|poller|monitor|outbound-dlq|outbound-metrics|outbound-tool|migrate|init|mcp. Daemon built explicitly via `new TgOutboundDaemon(...)`.
- **MCP (tg-ops):** queue-depth, dlq-list/retry/purge (capability-guarded), outbound-metrics, bot-list (tokens hidden), daemon-status (inferred from queue+metrics — no heartbeat yet).
- **Enablement:** `TgModuleEnablement` rows back the legacy enablement driver; engine driver uses bot_module_activations instead.

## Rules

TgBot::$hidden preserves token secrecy through serialization. Library providers never loadRoutesFrom() for tg routes. IP allowlist middleware is mandatory on both webhook routes.
