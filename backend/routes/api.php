<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'device.active'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('devices', [AuthController::class, 'devices']);
        Route::delete('devices/{id}', [AuthController::class, 'revokeDevice']);

        Route::post('two-factor/setup', [AuthController::class, 'twoFactorSetup']);
        Route::post('two-factor/confirm', [AuthController::class, 'twoFactorConfirm']);
        Route::post('two-factor/disable', [AuthController::class, 'twoFactorDisable']);

        Route::post('sync/push', [SyncController::class, 'push'])->middleware('throttle:60,1');
        Route::get('sync/pull', [SyncController::class, 'pull']);

        Route::get('dashboard', [DashboardController::class, 'show']);
    });
});
