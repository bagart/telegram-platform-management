<?php

declare(strict_types=1);

use BAGArt\TelegramBotManagement\Commands\TgBotManagerMigrate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

describe('TgBotManagerMigrate', function () {
    it('runs migrate successfully', function () {
        $this->artisan('migrate:tgbm')
            ->assertOk();
    });

    it('calls migrate with correct path', function () {
        $calledPath = null;

        Artisan::shouldReceive('call')
            ->once()
            ->withArgs(function (string $command, array $args = []) use (&$calledPath) {
                if ($command === 'migrate') {
                    $calledPath = $args['--path'] ?? null;
                    return true;
                }
                return false;
            })
            ->andReturn(0);

        $exitCode = app(TgBotManagerMigrate::class)->handle();

        expect($exitCode)->toBe(0)
            ->and($calledPath)->not->toBeNull()
            ->and($calledPath)->toContain('database/migrations');
    });
});
