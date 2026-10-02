<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Services;

use BAGArt\TelegramBotManagement\Enums\BotAvailability;
use BAGArt\TelegramBotManagement\Enums\BotClass;
use BAGArt\TelegramBotManagement\Enums\EntityKind;
use BAGArt\TelegramBotManagement\Exceptions\BotCatalogException;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Models\TgEntity;
use BAGArt\TelegramBotManagement\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;

/**
 * Bot catalog & external-bot connection (D12/D13, ADR-004).
 *
 * Catalog = platform-class bots: public ones for every workspace, private
 * ones only while the workspace holds the entitlement flag (superadmin-managed
 * settings['entitlements'] list; billing deferred). Adding a catalog bot to a
 * group is an ordinary Telegram t.me add-link flow — this service only answers
 * "which bots are addable" and "how does the link look".
 *
 * External bots: the owner supplies the token (encrypted at rest via the
 * model cast, masked/never logged — returned models keep it hidden) or
 * refuses and lands in registry-only mode. This service never calls the Bot
 * API; the token format check is syntactic only.
 */
final class BotCatalogService
{
    /** Workspace settings flag granting access to private catalog bots. */
    public const ENTITLEMENT_PRIVATE_BOTS = 'private_bots';

    public function __construct(
        private readonly EntityLinkService $entities,
    ) {
    }

    /**
     * Platform-class bots a workspace may add: public always, private only
     * with the entitlement flag; entities already linked into this workspace
     * are excluded.
     *
     * @return Collection<int, TgBot>
     */
    public function addableBots(int $workspaceId): Collection
    {
        $includePrivate = $this->hasEntitlement($workspaceId, self::ENTITLEMENT_PRIVATE_BOTS);

        $query = TgBot::query()->where('class', BotClass::Platform->value);
        if (! $includePrivate) {
            $query->where('availability', BotAvailability::Public->value);
        }

        $linkedBotIds = TgEntity::query()
            ->where('workspace_id', $workspaceId)
            ->where('kind', EntityKind::Bot->value)
            ->pluck('bot_id');

        return $query
            ->whereNotIn('bot_id', $linkedBotIds)
            ->orderBy('bot_id')
            ->get();
    }

    /**
     * Whether the workspace settings carry the given superadmin-managed
     * entitlement flag. Missing workspace / missing settings = no flags.
     */
    public function hasEntitlement(int $workspaceId, string $flag): bool
    {
        $workspace = Workspace::query()->find($workspaceId);
        if ($workspace === null) {
            return false;
        }

        /** @var array<string, mixed> $settings */
        $settings = $workspace->settings ?? [];
        $flags = $settings['entitlements'] ?? [];

        return is_array($flags) && in_array($flag, $flags, true);
    }

    /**
     * Telegram add-to-group link for a catalog bot.
     *
     * tg_bots has no username column, so the caller passes the bot username
     * (surfaces take it from Telegram getMe or the linked tg_entities.username).
     */
    public function addLink(string $botUsername): string
    {
        return 'https://t.me/'.ltrim($botUsername, '@').'?startgroup=new';
    }

    /**
     * Connect an owner-supplied external bot: upsert the row as
     * class=external with the token encrypted at rest (model cast). The raw
     * token is never logged and never leaves this method — the returned model
     * keeps it hidden from serialization. Also records the workspace↔bot
     * entity link (system-initiated: linked_by NULL).
     *
     * @throws BotCatalogException for platform bots or a token that does not
     *                             match the Telegram token format
     */
    public function connectExternal(int $workspaceId, string $botId, string $token): TgBot
    {
        $bot = $this->externalBotRow($botId);

        if (! preg_match('/^\d+:[A-Za-z0-9_-]{35}$/', $token)) {
            throw BotCatalogException::invalidToken();
        }

        $bot->class = BotClass::External;
        $bot->availability = BotAvailability::Private;
        $bot->external_token = $token;
        $bot->save();

        $this->entities->link(
            workspaceId: $workspaceId,
            kind: EntityKind::Bot,
            externalId: null,
            botId: $botId,
            actorUserId: 0,
        );

        return $bot;
    }

    /**
     * Registry-only fallback (ADR-004): the owner refuses to share a token,
     * so the bot is recorded with external_token = null.
     *
     * INVARIANT: external_token === null on an external-class row means the
     * platform must perform NO Bot API calls for this bot — no updates, no
     * polling/webhook processing, no outbound sends. Rows that already hold
     * a token keep it (this method never downgrades a connected bot).
     *
     * @throws BotCatalogException when the bot exists as a platform bot
     */
    public function registryOnly(int $workspaceId, string $botId): TgBot
    {
        $bot = $this->externalBotRow($botId);

        $bot->class = BotClass::External;
        $bot->availability = BotAvailability::Private;
        $bot->save();

        $this->entities->link(
            workspaceId: $workspaceId,
            kind: EntityKind::Bot,
            externalId: null,
            botId: $botId,
            actorUserId: 0,
        );

        return $bot;
    }

    /**
     * @throws BotCatalogException when the row belongs to a platform bot
     */
    private function externalBotRow(string $botId): TgBot
    {
        $bot = TgBot::query()->find($botId);

        if ($bot === null) {
            return new TgBot([
                'bot_id' => $botId,
                'token' => '',
            ]);
        }

        if ($bot->class === BotClass::Platform) {
            throw BotCatalogException::botExistsAsPlatform($botId);
        }

        return $bot;
    }
}
