<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users granted access to a bound Telegram group within its workspace
 * (group-scoped T2 grants land here in a later phase).
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('workspace_group_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('workspace_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['group_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_group_members');
    }
};
