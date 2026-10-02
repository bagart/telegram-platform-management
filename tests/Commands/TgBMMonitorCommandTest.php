<?php

declare(strict_types=1);

use BAGArt\TelegramBotManagement\Models\TgBot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('TgBMMonitorCommand', function () {
    it('fails when bot is not found', function () {
        $this->artisan('tgbm:monitor', ['bot_uuid' => 'nonexistent'])
            ->expectsOutput('Bot not found: nonexistent')
            ->assertFailed();
    });

    it('fails when API client throws', function () {
        TgBot::create(['bot_id' => 'bot1', 'token' => '123456:ABC']);

        $mock = Mockery::mock(\BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract::class);
        $mock->shouldReceive('request')->andThrow(new RuntimeException('Connection refused'));
        $this->app->instance(\BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract::class, $mock);

        $this->artisan('tgbm:monitor', ['bot_uuid' => 'bot1'])
            ->assertFailed();
    });
});
