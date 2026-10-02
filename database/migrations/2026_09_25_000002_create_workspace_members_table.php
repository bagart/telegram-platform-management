<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workspace membership (ADR-001): platform-side membership with role.
 * Owner is the workspace membership authority; status suspends access
 * without deleting history.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('workspace_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 16)->default('member');
            $table->string('status', 16)->default('active');
            $table->timestampTz('joined_at')->nullable();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_members');
    }
};
