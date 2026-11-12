<?php

use App\Http\Controllers\Api\V1\AdminUserAuditController;
use App\Http\Controllers\Api\V1\AdminUserController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ReadinessController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class)->name('api.v1.health');
    Route::get('/ready', ReadinessController::class)->middleware('throttle:30,1')->name('api.v1.ready');

    Route::prefix('auth')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:repasse-login')
            ->name('api.v1.auth.login');

        Route::middleware(['bearer.only', 'auth:api', 'session.current'])->group(function (): void {
            Route::get('/me', [AuthController::class, 'me'])->name('api.v1.auth.me');
            Route::post('/refresh', [AuthController::class, 'refresh'])
                ->middleware('throttle:repasse-refresh')->name('api.v1.auth.refresh');
            Route::post('/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
            Route::post('/password', [AuthController::class, 'changePassword'])
                ->middleware('throttle:repasse-password')->name('api.v1.auth.password');
        });
    });
    Route::prefix('admin')->middleware([
        'bearer.only', 'auth:api', 'session.current', 'permission:users.manage',
    ])->group(function (): void {
        Route::get('/users', [AdminUserController::class, 'index'])->name('api.v1.admin.users.index');
        Route::post('/users', [AdminUserController::class, 'store'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.admin.users.store');
        Route::patch('/users/{user}', [AdminUserController::class, 'update'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.admin.users.update');
        Route::get('/users/{user}/events', AdminUserAuditController::class)
            ->middleware('throttle:repasse-admin-read')->name('api.v1.admin.users.events');
        Route::patch('/users/{user}/status', [AdminUserController::class, 'status'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.admin.users.status');
    });
});
