<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotManagement\Http\Controllers;

use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramModuleEngine\Activation\ModuleActivationReader;
use BAGArt\TelegramModuleEngine\Registry\EngineModuleRegistry;
use BAGArt\TelegramModuleEngine\Settings\ConfigFileAccessContract;
use BAGArt\TelegramModuleEngine\Settings\SettingsScreenContribution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Generic web settings renderer — module-agnostic.
 *
 * Renders whatever settings screens the engine resolves for a bot.
 * Reads/writes module config files via ConfigFileAccessContract.
 * No per-module controllers; the descriptor drives the form.
 */
class SettingsScreenController
{
    public function __construct(
        private readonly EngineModuleRegistry $registry,
        private readonly ModuleActivationReader $activations,
        private readonly ConfigFileAccessContract $configAccess,
    ) {}

    /**
     * Screen listing: resolved settings screens for a selected bot,
     * filtered by module activation.
     */
    public function index(Request $request): Response
    {
        $bots = TgBot::query()->orderBy('bot_id')->get(['bot_id']);
        $selectedBotId = $request->query('bot_id', $bots->first()?->bot_id);

        $screens = [];
        if ($selectedBotId !== null) {
            $screens = $this->resolveScreensForBot($selectedBotId);
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
    public function show(string $botId, string $screenId): Response
    {
        $screen = $this->findScreen($botId, $screenId);

        if ($screen === null) {
            abort(404, 'Settings screen not found.');
        }

        $moduleId = $screen['moduleId'];
        $descriptor = $screen['descriptor'];
        $currentValues = $this->configAccess->read($moduleId);
        $mtime = $this->configAccess->mtime($moduleId);

        return Inertia::render('settings/modules/show', [
            'botId' => $botId,
            'moduleId' => $moduleId,
            'screenId' => $screenId,
            'label' => $screen['label'],
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
        $screen = $this->findScreen($botId, $screenId);

        if ($screen === null) {
            abort(404, 'Settings screen not found.');
        }

        $moduleId = $screen['moduleId'];
        $descriptor = $screen['descriptor'];

        $request->validate($this->buildValidationRules($descriptor));

        // Optimistic concurrency: check mtime
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
     * Resolve all settings screens for a bot (active modules only).
     *
     * @return list<array{screenId: string, moduleId: string, label: string, fieldsCount: int}>
     */
    private function resolveScreensForBot(string $botId): array
    {
        $activeModuleIds = $this->activations->activeModuleIds($botId);
        $screens = [];

        foreach ($activeModuleIds as $moduleId) {
            $definition = $this->registry->get($moduleId);
            if ($definition === null) {
                continue;
            }

            foreach ($definition->settingsScreens as $screen) {
                $screens[] = [
                    'screenId' => $screen->screenId,
                    'moduleId' => $moduleId,
                    'label' => $this->humanizeScreenId($screen->screenId),
                    'fieldsCount' => count($screen->descriptor->fields),
                ];
            }
        }

        return $screens;
    }

    /**
     * Find a specific screen for a bot (must be active).
     *
     * @return array{screenId: string, moduleId: string, label: string, descriptor: \BAGArt\TelegramModuleEngine\Settings\SettingsDescriptor}|null
     */
    private function findScreen(string $botId, string $screenId): ?array
    {
        $activeModuleIds = $this->activations->activeModuleIds($botId);

        foreach ($activeModuleIds as $moduleId) {
            $definition = $this->registry->get($moduleId);
            if ($definition === null) {
                continue;
            }

            foreach ($definition->settingsScreens as $screen) {
                if ($screen->screenId === $screenId) {
                    return [
                        'screenId' => $screen->screenId,
                        'moduleId' => $moduleId,
                        'label' => $this->humanizeScreenId($screen->screenId),
                        'descriptor' => $screen->descriptor,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Serialize fields to plain arrays for Inertia props.
     *
     * @param  list<\BAGArt\TelegramModuleEngine\Settings\SettingsField>  $fields
     * @return list<array{fieldId: string, type: string, default: mixed, label: ?string, description: ?string, min: ?int, max: ?int, options: ?list, required: bool}>
     */
    private function serializeFields(array $fields): array
    {
        return array_map(
            fn ($field) => [
                'fieldId' => $field->fieldId,
                'type' => $field->type->value,
                'default' => $field->default,
                'label' => $field->labelKey,
                'description' => $field->descriptionKey,
                'min' => $field->min,
                'max' => $field->max,
                'options' => $field->options,
                'required' => $field->required,
            ],
            $fields,
        );
    }

    /**
     * Filter stored values to only include keys that match defined fields.
     *
     * @param  array<string, mixed>  $values
     * @param  list<\BAGArt\TelegramModuleEngine\Settings\SettingsField>  $fields
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
