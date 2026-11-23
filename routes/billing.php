<?php

use App\Http\Controllers\Api\V1\CompanyBillingController as Company;
use App\Http\Controllers\Api\V1\PlatformBillingController as Platform;
use App\Http\Controllers\Api\V1\PlatformAnalyticsController as Analytics;
use App\Http\Middleware\PlatformOperator;
use Illuminate\Support\Facades\Route;

Route::post('v1/billing/notifications/{environment}', [Company::class,'webhook'])
    ->whereIn('environment',['test','production']);
Route::prefix('v1')->middleware(['bearer.only','auth:api','session.current'])->group(function () {
    Route::get('billing/overview',[Company::class,'overview']);
    Route::post('billing/checkout',[Company::class,'checkout'])->middleware('throttle:repasse-admin-write');
    Route::post('billing/subscriptions/{subscription}/cancel',[Company::class,'cancel'])->middleware('throttle:repasse-admin-write');
    Route::prefix('platform')->middleware([PlatformOperator::class,'throttle:repasse-admin-write'])->group(function () {
        Route::get('analytics/overview', [Analytics::class, 'overview']);
        Route::get('companies/{company}/360', [Analytics::class, 'company']);
        Route::get('users',[Platform::class,'users']);
        Route::get('plans',[Platform::class,'plans']);
        Route::post('plans',[Platform::class,'save']);
        Route::put('plans/{plan}',[Platform::class,'save']);
        Route::get('plans/{plan}/impact',[Platform::class,'impact']);
        Route::get('companies',[Platform::class,'companies']);
        Route::put('companies/{company}/plan',[Platform::class,'migrateCompany']);
        Route::get('companies/{company}/billing',[Platform::class,'ledger']);
        Route::get('gateways',[Platform::class,'gateways']);
        Route::put('gateways/{environment}',[Platform::class,'configure']);
        Route::post('gateways/{environment}/test',[Platform::class,'probe']);
    });
});
