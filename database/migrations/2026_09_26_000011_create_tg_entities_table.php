<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram entity registry (D2, ADR-003): first-class workspace-owned managed
 * objects (channel / chat group / bot). Distinct from tg_chats — that table is
 * a bot-scoped runtime projection of chats a bot has seen, while an entity may
 * exist before any bot observes it and must survive the chat-registry
 * lifecycle under workspace tenancy.
 *
 * Kind/target invariant (enforced at DB level where the driver allows it):
 *  - kind='bot'          → bot_id NOT NULL, external_id NULL
 *  - kind='channel'|'chat_group' → external_id NOT NULL, bot_id NULL
 * Any other kind value violates the same CHECK, so it doubles as the kind
 * allowlist. NULLs are distinct in unique indexes on both Postgres and
 * SQLite, so the per-kind unique pairs behave identically on both drivers.
 */
return new class () extends Migration {
    private const KIND_TARGET_CHECK = "(
        (kind = 'bot' AND bot_id IS NOT NULL AND external_id IS NULL)
        OR (kind IN ('channel', 'chat_group') AND external_id IS NOT NULL AND bot_id IS NULL)
    )";

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->createOnSqlite();

            return;
        }

        Schema::create('tg_entities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->bigInteger('external_id')->nullable();
            $table->string('bot_id', 20)->nullable();
            $table->string('title', 255)->nullable();
            $table->string('username', 64)->nullable();
            $table->bigInteger('linked_by')->nullable();
            $table->timestampTz('linked_at')->nullable();
            $table->timestampsTz();

            $table->foreign('bot_id')
                ->references('bot_id')->on('tg_bots')
                ->cascadeOnDelete();

            $table->unique(['workspace_id', 'kind', 'external_id']);
            $table->unique(['workspace_id', 'kind', 'bot_id']);
            $table->index(['workspace_id', 'kind']);
        });

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE tg_entities ADD CONSTRAINT tg_entities_kind_target_check CHECK '.self::KIND_TARGET_CHECK,
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tg_entities');
    }

    /**
     * SQLite can only attach CHECK constraints at CREATE TABLE time (no
     * ALTER TABLE ... ADD CONSTRAINT), so the constraint lives inside the
     * table definition here. The DDL mirrors the portable Schema::create
     * path above column for column.
     */
    private function createOnSqlite(): void
    {
        DB::statement(
            'CREATE TABLE tg_entities ('
            .'id integer primary key autoincrement not null, '
            .'workspace_id integer not null, '
            .'kind varchar(16) not null, '
            .'external_id integer, '
            .'bot_id varchar(20), '
            .'title varchar(255), '
            .'username varchar(64), '
            .'linked_by integer, '
            .'linked_at datetime, '
            .'created_at datetime, '
            .'updated_at datetime, '
            .'constraint tg_entities_workspace_id_foreign foreign key (workspace_id) references workspaces (id) on delete cascade, '
            .'constraint tg_entities_bot_id_foreign foreign key (bot_id) references tg_bots (bot_id) on delete cascade, '
            .'constraint tg_entities_kind_target_check check '.self::KIND_TARGET_CHECK.', '
            .'constraint tg_entities_workspace_id_kind_external_id_unique unique (workspace_id, kind, external_id), '
            .'constraint tg_entities_workspace_id_kind_bot_id_unique unique (workspace_id, kind, bot_id)'
            .')'
        );

        DB::statement(
            'CREATE INDEX tg_entities_workspace_id_kind_index ON tg_entities (workspace_id, kind)'
        );
    }
};
