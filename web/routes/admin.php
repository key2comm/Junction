<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AccountIpController;
use App\Http\Controllers\Admin\GatewayController;
use App\Http\Controllers\Admin\LedgerEntryController;
use App\Http\Controllers\Admin\RateDeckController;
use App\Http\Controllers\Admin\RateImportController;
use App\Http\Controllers\Admin\RoutingPlanController;
use App\Http\Controllers\Admin\TrunkController;
use Illuminate\Support\Facades\Route;

/*
 * Softswitch administration. Every admin user can manage everything for now;
 * customer-portal users (scoped to one account) come with the API phase.
 */
Route::middleware(['auth'])->group(function () {
    Route::resource('accounts', AccountController::class)->except(['destroy']);

    Route::scopeBindings()->group(function () {
        Route::post('accounts/{account}/ips', [AccountIpController::class, 'store'])->name('accounts.ips.store');
        Route::patch('accounts/{account}/ips/{ip}', [AccountIpController::class, 'update'])->name('accounts.ips.update');
        Route::delete('accounts/{account}/ips/{ip}', [AccountIpController::class, 'destroy'])->name('accounts.ips.destroy');
    });

    Route::get('accounts/{account}/route-test', [AccountController::class, 'routeTest'])
        ->middleware('throttle:60,1')
        ->name('accounts.route-test');

    Route::post('accounts/{account}/ledger', [LedgerEntryController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('accounts.ledger.store');

    Route::resource('rate-decks', RateDeckController::class)->except(['destroy']);
    Route::post('rate-decks/{rate_deck}/imports', [RateImportController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('rate-decks.imports.store');

    Route::resource('trunks', TrunkController::class)->except(['destroy']);
    Route::resource('trunks.gateways', GatewayController::class)->only(['store', 'edit', 'update', 'destroy'])->scoped();

    Route::resource('routing-plans', RoutingPlanController::class)->except(['destroy']);
    Route::scopeBindings()->group(function () {
        Route::post('routing-plans/{routing_plan}/routes', [RoutingPlanController::class, 'storeRoute'])->name('routing-plans.routes.store');
        Route::patch('routing-plans/{routing_plan}/routes/{route}', [RoutingPlanController::class, 'updateRoute'])->name('routing-plans.routes.update');
        Route::delete('routing-plans/{routing_plan}/routes/{route}', [RoutingPlanController::class, 'destroyRoute'])->name('routing-plans.routes.destroy');
    });
});
