<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\TenantConfigController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::post('/auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:20,1');

    Route::middleware(['tenant.auth', 'tenant.active'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::get('/tenant/config', [TenantConfigController::class, 'show']);
    });
});
