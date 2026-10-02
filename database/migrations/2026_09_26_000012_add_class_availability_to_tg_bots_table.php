<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bot ecosystem columns (D12/D13, ADR-004):
 *  - class: platform | external — who operates the bot;
 *  - availability: public | private — catalog visibility for workspaces;
 *  - external_token: the owner-supplied token of an external bot, encrypted
 *    at rest via the model cast (the existing plain `token` column keeps its
 *    semantics for TgDbTokenResolver — platform bots are untouched).
 *
 * Defaults backfill legacy rows (D11: no data migration required).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('tg_bots', function (Blueprint $table): void {
            if (! Schema::hasColumn('tg_bots', 'class')) {
                $table->string('class', 16)->default('platform');
            }

            if (! Schema::hasColumn('tg_bots', 'availability')) {
                $table->string('availability', 16)->default('public');
            }

            if (! Schema::hasColumn('tg_bots', 'external_token')) {
                $table->string('external_token')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tg_bots', function (Blueprint $table): void {
            foreach (['class', 'availability', 'external_token'] as $column) {
                if (Schema::hasColumn('tg_bots', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
