<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workspaces = tenants = admin groups (ADR-001). One platform user may
 * belong to many workspaces; slug is the stable public identifier.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->json('settings')->nullable();
            $table->timestampsTz();

            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
