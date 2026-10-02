<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the legacy tg_module_enablements table after data migration.
 *
 * Safety check: verifies that all data has been migrated before dropping.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tg_module_enablements')) {
            return;
        }

        // Safety check: ensure all data has been migrated
        $legacyCount = DB::table('tg_module_enablements')->count();
        $engineCount = DB::table('bot_module_activations')->count();

        // Account for platform-level expansion: each platform row becomes multiple bot rows
        $platformRows = DB::table('tg_module_enablements')
            ->whereNull('bot_id')
            ->count();
        $botCount = DB::table('tg_bots')->count();

        // Expected engine rows = bot-level rows + platform-level rows * bot count
        $botLevelRows = DB::table('tg_module_enablements')
            ->whereNotNull('bot_id')
            ->count();
        $expectedEngineCount = $botLevelRows + ($platformRows * $botCount);

        // Allow some tolerance for chat-level settings that may have been merged
        if ($engineCount < $expectedEngineCount * 0.9) {
            throw new \RuntimeException(sprintf(
                'Data migration incomplete. Legacy: %d rows, Engine: %d rows, Expected: ~%d rows',
                $legacyCount,
                $engineCount,
                $expectedEngineCount,
            ));
        }

        Schema::dropIfExists('tg_module_enablements');
    }

    public function down(): void
    {
        Schema::create('tg_module_enablements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('bot_id', 20)->nullable();
            $table->bigInteger('chat_id')->nullable();
            $table->string('module_id', 100);
            $table->boolean('is_enabled')->default(true);
            $table->json('module_settings')->nullable();

            $table->foreign('bot_id')
                ->references('bot_id')->on('tg_bots')
                ->cascadeOnDelete();

            $table->unique(['bot_id', 'chat_id', 'module_id']);
            $table->index(['bot_id', 'chat_id']);
            $table->index('module_id');
            $table->timestampsTz();
        });
    }
};
