<?php

declare(strict_types=1);

use BAGArt\TelegramBotManagement\Commands\TgMcpStartCommand;
use Illuminate\Support\Facades\Artisan;

describe('TgMcpStartCommand', function () {
    it('delegates to mcp:start with tg-ops handle', function () {
        Artisan::shouldReceive('call')
            ->once()
            ->withArgs(function (string $command, array $args = []) {
                return $command === 'mcp:start' && $args === ['handle' => 'tg-ops'];
            })
            ->andReturn(0);

        $exitCode = app(TgMcpStartCommand::class)->handle();

        expect($exitCode)->toBe(0);
    });

    it('returns success when mcp:start succeeds', function () {
        Artisan::shouldReceive('call')->once()->andReturn(0);

        $exitCode = app(TgMcpStartCommand::class)->handle();

        expect($exitCode)->toBe(0);
    });

    it('propagates failure from mcp:start', function () {
        Artisan::shouldReceive('call')->once()->andReturn(1);

        $exitCode = app(TgMcpStartCommand::class)->handle();

        expect($exitCode)->toBe(1);
    });
});
