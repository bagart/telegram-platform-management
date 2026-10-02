<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('TgBMPollerCommand', function () {
    it('fails when bot is not found', function () {
        $this->artisan('tgbm:poll', ['bot_uuid' => 'nonexistent-uuid'])
            ->expectsOutput('Bot not found: nonexistent-uuid')
            ->assertFailed();
    });

    it('fails for multiple nonexistent UUIDs', function () {
        $this->artisan('tgbm:poll', ['bot_uuid' => 'uuid-a'])
            ->expectsOutput('Bot not found: uuid-a')
            ->assertFailed();

        $this->artisan('tgbm:poll', ['bot_uuid' => 'uuid-b'])
            ->expectsOutput('Bot not found: uuid-b')
            ->assertFailed();
    });
});
