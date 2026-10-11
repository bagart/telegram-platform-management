<?php

declare(strict_types=1);

use BAGArt\TelegramBotManagement\Http\Controllers\ModuleActivationController;
use Illuminate\Support\Facades\Route;

// Web activation surface (W-D4): the Channel-B management.settings.* screen
// routes were retired (Q4 option 1); this group is the platform web surface
// for flipping bot-scoped module activation.
Route::middleware(['web', 'auth', 'verified'])
    ->name('management.modules.')
    ->group(function (): void {
        Route::get('/modules', [ModuleActivationController::class, 'index'])->name('index');
        Route::post('/modules/{botId}/{moduleId}/enable', [ModuleActivationController::class, 'enable'])->name('enable');
        Route::post('/modules/{botId}/{moduleId}/disable', [ModuleActivationController::class, 'disable'])->name('disable');
    });
