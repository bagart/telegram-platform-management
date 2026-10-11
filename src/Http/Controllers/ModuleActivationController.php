<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Http\Controllers;

use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Services\BotOwnershipResolver;
use BAGArt\TelegramBot\Modules\TgModuleCapability;
use BAGArt\TelegramModuleEngine\Activation\ActivationOutcome;
use BAGArt\TelegramModuleEngine\Activation\ActivationResult;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationReader;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationService;
use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Web module-activation surface: list every registry module for an owned
 * bot and flip its bot-scoped enablement.
 *
 * Writes delegate to ModuleActivationService (CAS + dependency blockers);
 * audit rows and the engine route-cache refresh arrive through the
 * BotModuleEnabled|Disabled listeners, so this controller wires no
 * listener, event or audit code of its own.
 *
 * Access model copies the settings-screen sibling: management defines no
 * Gate policies — per-request workspace-owner resolution via
 * BotOwnershipResolver (BotAdmin = owner of the specific bot).
 *
 * Injects the concrete ModuleActivationService because
 * ModuleActivationWriterContract only declares setEnabled; widening the
 * contract is deferred until a second non-service consumer exists.
 */
final class ModuleActivationController
{
    public function __construct(
        private readonly ModuleActivationService $activations,
        private readonly ModuleActivationReader $reader,
        private readonly EngineModuleRegistry $registry,
        private readonly BotOwnershipResolver $ownership,
    ) {
    }

    /**
     * Activation overview for the user's bots: every registry entry with
     * its per-bot state — including modules that are currently off, unlike
     * the settings-screens index which only lists active modules.
     */
    public function index(Request $request): Response
    {
        $ownedBotIds = $this->ownedBotIds(Auth::id());

        $bots = TgBot::query()
            ->whereIn('bot_id', $ownedBotIds)
            ->orderBy('bot_id')
            ->get(['bot_id']);

        $requestedBotId = $request->query('bot_id');
        $selectedBotId = is_string($requestedBotId) && in_array($requestedBotId, $ownedBotIds, true)
            ? $requestedBotId
            : $bots->first()?->bot_id;

        return Inertia::render('modules/index', [
            'bots' => $bots,
            'selectedBotId' => $selectedBotId,
            'modules' => $selectedBotId === null ? [] : $this->moduleStates($selectedBotId),
            'labels' => $this->labels(),
        ]);
    }

    /** Idempotent enable: already-enabled answers success without a write. */
    public function enable(string $botId, string $moduleId): JsonResponse|RedirectResponse
    {
        return $this->flip($botId, $moduleId, enable: true);
    }

    /** Idempotent disable: already-disabled answers success without a write. */
    public function disable(string $botId, string $moduleId): JsonResponse|RedirectResponse
    {
        return $this->flip($botId, $moduleId, enable: false);
    }

    private function flip(string $botId, string $moduleId, bool $enable): JsonResponse|RedirectResponse
    {
        $userId = Auth::id();

        if (! $this->isBotOwnedBy($botId, $userId)) {
            abort(403, Lang::get('management::settings.bot_not_owned'));
        }

        if ($this->registry->get($moduleId) === null) {
            abort(404, Lang::get('management::modules.module_not_found'));
        }

        $actorId = $userId !== null ? (string) $userId : null;
        $result = $enable
            ? $this->activations->enable($botId, $moduleId, null, $actorId, 'user')
            : $this->activations->disable($botId, $moduleId, null, $actorId, 'user');

        $failure = $this->failureResponse($result);
        if ($failure !== null) {
            return $failure;
        }

        return to_route('management.modules.index', ['bot_id' => $botId])
            ->with('success', Lang::get(
                $enable ? 'management::modules.enabled_flash' : 'management::modules.disabled_flash',
                ['module' => $moduleId, 'bot' => $botId],
            ));
    }

    /** 422 for structured write refusals (blocked / lost race); null when applied. */
    private function failureResponse(ActivationResult $result): ?JsonResponse
    {
        if ($result->outcome === ActivationOutcome::Blocked) {
            return response()->json([
                'message' => Lang::get('management::modules.blocked'),
                'blockers' => array_map(
                    static fn ($blocker): array => [
                        'module_id' => $blocker->moduleId,
                        'reason' => $blocker->reason->value,
                        'message' => $blocker->message,
                    ],
                    $result->blockers,
                ),
            ], 422);
        }

        if ($result->outcome === ActivationOutcome::ConcurrentModification) {
            return response()->json([
                'message' => Lang::get('management::modules.concurrent_modification'),
                'current_revision' => $result->revision,
            ], 422);
        }

        return null;
    }

    /**
     * Registry entries with per-bot activation state for the page table.
     *
     * @return list<array{id: string, name: string, version: string, capabilities: list<string>, defaultEnabled: bool, platformEnabled: bool, enabled: bool, explicitlyDisabled: bool}>
     */
    private function moduleStates(string $botId): array
    {
        $states = [];
        foreach ($this->registry->all() as $definition) {
            $states[] = [
                'id' => $definition->id(),
                'name' => $definition->descriptor->name,
                'version' => $definition->descriptor->version,
                'capabilities' => array_map(
                    static fn (TgModuleCapability $capability): string => $capability->value,
                    $definition->descriptor->capabilities,
                ),
                'defaultEnabled' => $definition->descriptor->defaultEnabled,
                'platformEnabled' => $definition->enabled,
                'enabled' => $this->reader->isEffectivelyEnabled($botId, $definition->id()),
                'explicitlyDisabled' => $this->reader->isExplicitlyDisabled($botId, $definition->id()),
            ];
        }

        return $states;
    }

    /**
     * Page label tree for the request locale (superadmin-page pattern):
     * the React page renders translated props, no client-side catalog.
     *
     * @return array<string, mixed>
     */
    private function labels(): array
    {
        $labels = Lang::get('management::modules.page');

        return is_array($labels) ? $labels : [];
    }

    /**
     * Bot IDs owned by a user (workspace-owner resolution).
     *
     * @return list<string>
     */
    private function ownedBotIds(?int $userId): array
    {
        if ($userId === null) {
            return [];
        }

        return $this->ownership->ownedBotIds($userId);
    }

    /** Check if a bot is owned by a user (workspace-owner resolution). */
    private function isBotOwnedBy(string $botId, ?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return $this->ownership->isOwner($userId, $botId);
    }
}
