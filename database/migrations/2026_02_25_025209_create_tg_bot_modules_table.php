<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tg_bot_modules', function (Blueprint $table) {
            $table->id();
            $table->string('bot_id', 20);
            $table->bigInteger('chat_id');
            $table->bigInteger('message_thread_id')->nullable();
            $table->timestampsTz();

            $table->unique(['bot_id', 'chat_id', 'message_thread_id']);
            $table->foreign('bot_id')->references('bot_id')->on('tg_bots')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tg_bot_modules');
    }
};
