<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migrate data from tg_module_enablements (legacy) to bot_module_activations (engine).
 *
 * Handles three scope levels:
 * - Platform-level (bot_id IS NULL): expands to per-bot rows for every bot
 * - Bot-level (bot_id NOT NULL, chat_id IS NULL): direct upsert
 * - Chat-level (bot_id + chat_id NOT NULL): stores in module_settings JSONB
 *   using "chatId:fieldId" key convention supported by DatabaseSettingsStorage
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('tg_module_enablements')
            ->orderBy('created_at')
            ->get();

        $bots = DB::table('tg_bots')
            ->pluck('bot_id')
            ->toArray();

        foreach ($rows as $row) {
            if ($row->bot_id === null) {
                // Platform-level: expand to per-bot rows
                foreach ($bots as $botId) {
                    $this->upsertActivation(
                        botId: $botId,
                        moduleId: $row->module_id,
                        isEnabled: $row->is_enabled,
                        moduleSettings: $row->module_settings,
                        createdAt: $row->created_at,
                        updatedAt: $row->updated_at,
                    );
                }
            } elseif ($row->chat_id !== null) {
                // Chat-level: store in module_settings with chatId prefix
                $chatSettings = $this->migrateChatSettings(
                    chatId: $row->chat_id,
                    moduleSettings: $row->module_settings,
                    isEnabled: $row->is_enabled,
                );

                $this->upsertActivation(
                    botId: $row->bot_id,
                    moduleId: $row->module_id,
                    isEnabled: $row->is_enabled,
                    moduleSettings: $chatSettings,
                    createdAt: $row->created_at,
                    updatedAt: $row->updated_at,
                );
            } else {
                // Bot-level: direct upsert
                $this->upsertActivation(
                    botId: $row->bot_id,
                    moduleId: $row->module_id,
                    isEnabled: $row->is_enabled,
                    moduleSettings: $row->module_settings,
                    createdAt: $row->created_at,
                    updatedAt: $row->updated_at,
                );
            }
        }
    }

    public function down(): void
    {
        $rows = DB::table('bot_module_activations')
            ->orderBy('created_at')
            ->get();

        foreach ($rows as $row) {
            $moduleSettings = is_array(json_decode($row->module_settings, true))
                ? json_decode($row->module_settings, true)
                : [];

            // Extract chat-level settings from module_settings
            $chatSettings = $this->extractChatSettings($moduleSettings);

            // Create bot-level row (status mapped back to is_enabled)
            DB::table('tg_module_enablements')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'bot_id' => $row->bot_id,
                'chat_id' => null,
                'module_id' => $row->module_id,
                'is_enabled' => $row->status === 'enabled',
                'module_settings' => $chatSettings['bot_settings'] ?? null,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);

            // Create chat-level rows if any
            foreach ($chatSettings['chat_settings'] as $chatId => $chatModuleSettings) {
                DB::table('tg_module_enablements')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'bot_id' => $row->bot_id,
                    'chat_id' => (int) $chatId,
                    'module_id' => $row->module_id,
                    'is_enabled' => $row->status === 'enabled',
                    'module_settings' => $chatModuleSettings !== [] ? $chatModuleSettings : null,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            }
        }
    }

    private function upsertActivation(
        string $botId,
        string $moduleId,
        bool $isEnabled,
        mixed $moduleSettings,
        string $createdAt,
        string $updatedAt,
    ): void {
        $status = $isEnabled ? 'enabled' : 'disabled';

        // Check if row already exists (idempotent)
        $existing = DB::table('bot_module_activations')
            ->where('bot_id', $botId)
            ->where('module_id', $moduleId)
            ->first();

        if ($existing !== null) {
            // Merge module_settings if both have values
            $existingSettings = is_array(json_decode($existing->module_settings, true))
                ? json_decode($existing->module_settings, true)
                : [];
            $newSettings = is_array($moduleSettings) ? $moduleSettings : [];
            $mergedSettings = array_merge($existingSettings, $newSettings);

            DB::table('bot_module_activations')
                ->where('bot_id', $botId)
                ->where('module_id', $moduleId)
                ->update([
                    'status' => $status,
                    'module_settings' => $mergedSettings !== [] ? json_encode($mergedSettings) : null,
                    'updated_at' => $updatedAt,
                ]);
        } else {
            DB::table('bot_module_activations')->insert([
                'bot_id' => $botId,
                'module_id' => $moduleId,
                'status' => $status,
                'module_settings' => $moduleSettings,
                'revision' => 1,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]);
        }
    }

    /**
     * Migrate chat-level settings to module_settings JSONB using "chatId:fieldId" keys.
     */
    private function migrateChatSettings(
        int $chatId,
        mixed $moduleSettings,
        bool $isEnabled,
    ): ?string {
        if (! is_array($moduleSettings) || $moduleSettings === []) {
            // Store chat-level enabled state as a special key
            return json_encode(["{$chatId}:__enabled__" => $isEnabled]);
        }

        $chatKeyed = [];
        foreach ($moduleSettings as $fieldId => $value) {
            $chatKeyed["{$chatId}:{$fieldId}"] = $value;
        }

        // Also store chat-level enabled state
        $chatKeyed["{$chatId}:__enabled__"] = $isEnabled;

        return json_encode($chatKeyed);
    }

    /**
     * Extract chat-level settings from module_settings JSONB.
     *
     * @return array{bot_settings: array<string, mixed>, chat_settings: array<string, array<string, mixed>>}
     */
    private function extractChatSettings(array $moduleSettings): array
    {
        $botSettings = [];
        $chatSettings = [];

        foreach ($moduleSettings as $key => $value) {
            if (str_contains($key, ':')) {
                [$chatId, $fieldId] = explode(':', $key, 2);
                $chatSettings[$chatId][$fieldId] = $value;
            } else {
                $botSettings[$key] = $value;
            }
        }

        // Remove __enabled__ keys from final settings
        foreach ($chatSettings as &$chatModuleSettings) {
            unset($chatModuleSettings['__enabled__']);
            $chatModuleSettings = array_filter(
                $chatModuleSettings,
                static fn ($v, $k) => $k !== '__enabled__',
                ARRAY_FILTER_USE_BOTH,
            );
        }
        unset($chatModuleSettings);

        return [
            'bot_settings' => $botSettings,
            'chat_settings' => $chatSettings,
        ];
    }
};
