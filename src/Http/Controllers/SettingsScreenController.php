<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Http\Controllers;

use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotManagement\Services\BotOwnershipResolver;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationReader;
use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use BAGArt\TelegramModuleEngine\Settings\ConfigFileAccessContract;
use BAGArt\TelegramModuleEngine\Settings\SettingsField;
use BAGArt\TelegramModuleEngine\Settings\WebAccessLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Generic web settings renderer — module-agnostic.
 *
 * Renders whatever settings screens the engine resolves for a bot.
 * Reads/writes module config files via ConfigFileAccessContract.
 * No per-module controllers; the descriptor drives the form.
 *
 * Access levels (ownership resolved via BotOwnershipResolver over workspaces;
 * tg_bot_owners is a legacy fact, never a rights source — D7/D11):
 *  - BotAdmin: user is a workspace owner of a workspace linking the bot
 *  - PlatformAdmin: user owns ≥1 bot under the same resolution
 */
class SettingsScreenController
{
    public function __construct(
        private readonly EngineModuleRegistry $registry,
        private readonly ModuleActivationReader $activations,
        private readonly ConfigFileAccessContract $configAccess,
        private readonly BotOwnershipResolver $ownership,
    ) {
    }

    /**
     * Screen listing: resolved settings screens for a selected bot,
     * filtered by module activation and user's bot ownership.
     */
    public function index(Request $request): Response
    {
        $userId = Auth::id();
        $ownedBotIds = $this->ownedBotIds($userId);

        $bots = TgBot::query()
            ->whereIn('bot_id', $ownedBotIds)
            ->orderBy('bot_id')
            ->get(['bot_id']);

        $selectedBotId = $request->query('bot_id', $bots->first()?->bot_id);

        $screens = [];
        if ($selectedBotId !== null && in_array($selectedBotId, $ownedBotIds, true)) {
            $screens = $this->resolveScreensForBot($selectedBotId, $userId);
        }

        return Inertia::render('settings/modules/index', [
            'bots' => $bots,
            'selectedBotId' => $selectedBotId,
            'screens' => $screens,
        ]);
    }

    /**
     * Show a specific settings screen with current values.
     */
    public function show(Request $request, string $botId, string $screenId): Response
    {
        $userId = Auth::id();

        if (! $this->isBotOwnedBy($botId, $userId)) {
            abort(403, 'You do not own this bot.');
        }

        $screen = $this->findScreen($botId, $screenId, $userId);

        if ($screen === null) {
            abort(404, 'Settings screen not found.');
        }

        $this->authorizeScreen($screen['accessLevel'], $botId, $userId);

        $moduleId = $screen['moduleId'];
        $descriptor = $screen['descriptor'];
        $currentValues = $this->configAccess->read($moduleId);
        $mtime = $this->configAccess->mtime($moduleId);

        $component = $screen['component'] ?? 'settings/modules/show';

        return Inertia::render($component, [
            'botId' => $botId,
            'moduleId' => $moduleId,
            'screenId' => $screenId,
            'label' => $this->resolveI18nKey($screen['labelKey']),
            'fields' => $this->serializeFields($descriptor->fields),
            'values' => $this->filterValuesForFields($currentValues, $descriptor->fields),
            'mtime' => $mtime,
        ]);
    }

    /**
     * Save settings via ConfigFileAccessContract with optimistic concurrency.
     */
    public function update(Request $request, string $botId, string $screenId): JsonResponse|RedirectResponse
    {
        $userId = Auth::id();

        if (! $this->isBotOwnedBy($botId, $userId)) {
            abort(403, 'You do not own this bot.');
        }

        $screen = $this->findScreen($botId, $screenId, $userId);

        if ($screen === null) {
            abort(404, 'Settings screen not found.');
        }

        $this->authorizeScreen($screen['accessLevel'], $botId, $userId);

        $moduleId = $screen['moduleId'];
        $descriptor = $screen['descriptor'];

        $request->validate($this->buildValidationRules($descriptor));

        $clientMtime = (int) $request->input('_mtime', 0);
        $serverMtime = $this->configAccess->mtime($moduleId);

        if ($clientMtime !== 0 && $clientMtime !== $serverMtime) {
            $currentValues = $this->configAccess->read($moduleId);

            return response()->json([
                'message' => 'Settings were modified by someone else.',
                'values' => $this->filterValuesForFields($currentValues, $descriptor->fields),
                'mtime' => $serverMtime,
            ], 409);
        }

        $values = $this->extractFieldValues($request, $descriptor);
        $this->configAccess->write($moduleId, $values);

        return $request->expectsJson()
            ? response()->json([
                'message' => 'Settings saved.',
                'mtime' => $this->configAccess->mtime($moduleId),
            ])
            : to_route('management.settings.index', ['bot_id' => $botId])
                ->with('success', 'Settings saved.');
    }

    /**
     * Get bot IDs owned by a user (workspace-owner resolution).
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

    /**
     * Check if a bot is owned by a user (workspace-owner resolution).
     */
    private function isBotOwnedBy(string $botId, ?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return $this->ownership->isOwner($userId, $botId);
    }

    /**
     * Authorize screen access based on WebAccessLevel.
     *
     * PlatformAdmin: user must own ≥1 bot (same resolution as BotAdmin).
     * BotAdmin: user must own the specific bot (already checked by caller).
     */
    private function authorizeScreen(WebAccessLevel $accessLevel, string $botId, ?int $userId): void
    {
        if ($accessLevel === WebAccessLevel::PlatformAdmin) {
            $ownsAnyBot = $userId !== null && $this->ownership->ownedBotIds($userId) !== [];

            if (! $ownsAnyBot) {
                abort(403, 'Platform admin access required.');
            }
        }

        // BotAdmin is already enforced by the isBotOwnedBy check in callers.
    }

    /**
     * Resolve all settings screens for a bot (active modules only),
     * filtered by access level.
     *
     * @return list<array{screenId: string, moduleId: string, label: string, labelKey: string, component: ?string, accessLevel: string, fieldsCount: int}>
     */
    private function resolveScreensForBot(string $botId, ?int $userId): array
    {
        $activeModuleIds = $this->activations->activeModuleIds($botId);
        $screens = [];

        foreach ($activeModuleIds as $moduleId) {
            $definition = $this->registry->get($moduleId);
            if ($definition === null) {
                continue;
            }

            foreach ($definition->settingsScreens as $screen) {
                $accessLevel = $screen->web?->accessLevel ?? WebAccessLevel::BotAdmin;

                if (! $this->canAccess($accessLevel, $botId, $userId)) {
                    continue;
                }

                $screens[] = [
                    'screenId' => $screen->screenId,
                    'moduleId' => $moduleId,
                    'label' => $this->humanizeScreenId($screen->screenId),
                    'labelKey' => $screen->screenId,
                    'component' => $screen->web?->component,
                    'accessLevel' => $accessLevel->value,
                    'fieldsCount' => count($screen->descriptor->fields),
                ];
            }
        }

        return $screens;
    }

    /**
     * Check if a user can access a screen at the given access level.
     */
    private function canAccess(WebAccessLevel $accessLevel, string $botId, ?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return match ($accessLevel) {
            WebAccessLevel::BotAdmin => $this->isBotOwnedBy($botId, $userId),
            WebAccessLevel::PlatformAdmin => $this->ownership->ownedBotIds($userId) !== [],
        };
    }

    /**
     * Find a specific screen for a bot (must be active and user-authorized).
     *
     * @return array{screenId: string, moduleId: string, label: string, labelKey: string, component: ?string, accessLevel: WebAccessLevel, descriptor: \BAGArt\TelegramModuleEngine\Settings\SettingsDescriptor}|null
     */
    private function findScreen(string $botId, string $screenId, ?int $userId): ?array
    {
        $activeModuleIds = $this->activations->activeModuleIds($botId);

        foreach ($activeModuleIds as $moduleId) {
            $definition = $this->registry->get($moduleId);
            if ($definition === null) {
                continue;
            }

            foreach ($definition->settingsScreens as $screen) {
                if ($screen->screenId === $screenId) {
                    $accessLevel = $screen->web?->accessLevel ?? WebAccessLevel::BotAdmin;

                    if (! $this->canAccess($accessLevel, $botId, $userId)) {
                        continue;
                    }

                    return [
                        'screenId' => $screen->screenId,
                        'moduleId' => $moduleId,
                        'label' => $this->humanizeScreenId($screen->screenId),
                        'labelKey' => $screen->screenId,
                        'component' => $screen->web?->component,
                        'accessLevel' => $accessLevel,
                        'descriptor' => $screen->descriptor,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Resolve an i18n key to a translated string, falling back to humanized key.
     */
    private function resolveI18nKey(?string $key): string
    {
        if ($key === null) {
            return '';
        }

        $translation = Lang::get("management::settings.{$key}");

        return $translation !== "settings.{$key}" ? $translation : $this->humanizeScreenId($key);
    }

    /**
     * Serialize fields to plain arrays for Inertia props, resolving i18n keys.
     *
     * @param  list<SettingsField>  $fields
     * @return list<array{fieldId: string, type: string, default: mixed, label: string, description: string, min: ?int, max: ?int, options: ?list, required: bool}>
     */
    private function serializeFields(array $fields): array
    {
        return array_map(
            fn ($field) => [
                'fieldId' => $field->fieldId,
                'type' => $field->type->value,
                'default' => $field->default,
                'label' => $this->resolveI18nKey($field->labelKey) ?: $this->humanizeFieldId($field->fieldId),
                'description' => $this->resolveI18nKey($field->descriptionKey),
                'min' => $field->min,
                'max' => $field->max,
                'options' => $this->resolveOptionLabels($field->options),
                'required' => $field->required,
            ],
            $fields,
        );
    }

    /**
     * Resolve enum option label keys to translated strings.
     *
     * @param  list<array{value: string, labelKey: string}>|null  $options
     * @return list<array{value: string, label: string}>|null
     */
    private function resolveOptionLabels(?array $options): ?array
    {
        if ($options === null) {
            return null;
        }

        return array_map(
            fn ($opt) => [
                'value' => $opt['value'],
                'label' => $this->resolveI18nKey($opt['labelKey']) ?: $opt['value'],
            ],
            $options,
        );
    }

    /**
     * Convert a field ID like 'summarizer.model' to 'Summarizer Model'.
     */
    private function humanizeFieldId(string $fieldId): string
    {
        return ucfirst(str_replace('.', ' ', $fieldId));
    }

    /**
     * Filter stored values to only include keys that match defined fields.
     *
     * @param  array<string, mixed>  $values
     * @param  list<SettingsField>  $fields
     * @return array<string, mixed>
     */
    private function filterValuesForFields(array $values, array $fields): array
    {
        $fieldIds = array_map(
            fn ($field) => $field->fieldId,
            $fields,
        );

        return array_filter(
            $values,
            fn ($key) => in_array($key, $fieldIds, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Build Laravel validation rules from the field descriptor.
     *
     * @return array<string, mixed>
     */
    private function buildValidationRules(\BAGArt\TelegramModuleEngine\Settings\SettingsDescriptor $descriptor): array
    {
        $rules = [];

        foreach ($descriptor->fields as $field) {
            $fieldRules = [];

            if ($field->required) {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'nullable';
            }

            match ($field->type) {
                \BAGArt\TelegramModuleEngine\Settings\SettingsFieldType::Int => $fieldRules[] = 'integer',
                \BAGArt\TelegramModuleEngine\Settings\SettingsFieldType::Float => $fieldRules[] = 'numeric',
                \BAGArt\TelegramModuleEngine\Settings\SettingsFieldType::Bool => $fieldRules[] = 'boolean',
                \BAGArt\TelegramModuleEngine\Settings\SettingsFieldType::String => $fieldRules[] = 'string',
                \BAGArt\TelegramModuleEngine\Settings\SettingsFieldType::Text => $fieldRules[] = 'string',
                \BAGArt\TelegramModuleEngine\Settings\SettingsFieldType::Enum => $fieldRules[] = 'string',
            };

            if ($field->min !== null && in_array($field->type->value, ['int', 'float'], true)) {
                $fieldRules[] = "min:{$field->min}";
            }

            if ($field->max !== null && in_array($field->type->value, ['int', 'float'], true)) {
                $fieldRules[] = "max:{$field->max}";
            }

            if ($field->options !== null && $field->type->value === 'enum') {
                $allowed = array_map(fn ($opt) => $opt['value'], $field->options);
                $fieldRules[] = 'in:' . implode(',', $allowed);
            }

            $rules[$field->fieldId] = $fieldRules;
        }

        $rules['_mtime'] = ['nullable', 'integer'];

        return $rules;
    }

    /**
     * Extract validated field values from the request.
     *
     * @return array<string, mixed>
     */
    private function extractFieldValues(Request $request, \BAGArt\TelegramModuleEngine\Settings\SettingsDescriptor $descriptor): array
    {
        $values = [];

        foreach ($descriptor->fields as $field) {
            $value = $request->input($field->fieldId);

            if ($value === null && ! $field->required) {
                continue;
            }

            $values[$field->fieldId] = match ($field->type) {
                \BAGArt\TelegramModuleEngine\Settings\SettingsFieldType::Int => (int) $value,
                \BAGArt\TelegramModuleEngine\Settings\SettingsFieldType::Float => (float) $value,
                \BAGArt\TelegramModuleEngine\Settings\SettingsFieldType::Bool => (bool) $value,
                default => (string) $value,
            };
        }

        return $values;
    }

    /**
     * Convert a screen ID like 'summarizer.settings' to 'Summarizer Settings'.
     */
    private function humanizeScreenId(string $screenId): string
    {
        return ucfirst(str_replace('.', ' ', $screenId));
    }
}
