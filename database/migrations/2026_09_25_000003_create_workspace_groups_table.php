<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram groups bound to a workspace (D5: scope workspace → entity).
 * Rows are created later via a server-side getChatAdministrators
 * verification call — this table only holds the binding.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('workspace_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_groups');
    }
};
