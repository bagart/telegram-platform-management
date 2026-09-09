<?php

declare(strict_types=1);

use BAGArt\TelegramBotManagement\Http\Controllers\SettingsScreenController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified'])
    ->prefix('modules/settings')
    ->name('management.settings.')
    ->group(function (): void {
        Route::get('/', [SettingsScreenController::class, 'index'])->name('index');
        Route::get('/{botId}/{screenId}', [SettingsScreenController::class, 'show'])->name('show');
        Route::put('/{botId}/{screenId}', [SettingsScreenController::class, 'update'])->name('update');
    });
