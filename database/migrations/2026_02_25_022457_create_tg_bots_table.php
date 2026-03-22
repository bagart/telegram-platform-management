<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tg_bots', function (Blueprint $table) {
            $table->string('bot_id', 20)->primary();
            $table->string('token');
            $table->string('secret_token')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tg_bots');
    }
};
