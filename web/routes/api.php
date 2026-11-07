<?php

use App\Http\Controllers\Admin\QualityController;
use App\Http\Controllers\Api\V1\AccountsController;
use App\Http\Controllers\Api\V1\CdrsController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\RateDecksController;
use App\Http\Controllers\Api\V1\RoutingController;
use App\Http\Middleware\ApiAuth;
use Illuminate\Support\Facades\Route;

/*
 * REST API v1. Bearer-token auth (Settings → API tokens), stateless, JSON.
 * Every route here must be documented in resources/api/openapi.json (a test checks).
 */
Route::prefix('v1')->group(function () {
    Route::get('openapi.json', fn () => response(file_get_contents(resource_path('api/openapi.json')), 200,
        ['Content-Type' => 'application/json', 'Cache-Control' => 'public, max-age=300']))
        ->name('api.openapi');

    Route::middleware([ApiAuth::class, 'throttle:api'])->group(function () {
        Route::get('me', MeController::class);

        $can = fn (string $ability) => ApiAuth::class.':'.$ability;

        Route::middleware($can('accounts:read'))->group(function () {
            Route::get('accounts', [AccountsController::class, 'index']);
            Route::get('accounts/{account}', [AccountsController::class, 'show']);
            Route::get('accounts/{account}/ips', [AccountsController::class, 'ips']);
            Route::get('accounts/{account}/ledger', [AccountsController::class, 'ledger']);
        });
        Route::middleware($can('accounts:write'))->group(function () {
            Route::post('accounts', [AccountsController::class, 'store']);
            Route::patch('accounts/{account}', [AccountsController::class, 'update']);
            Route::post('accounts/{account}/ips', [AccountsController::class, 'storeIp']);
            Route::delete('accounts/{account}/ips/{ip}', [AccountsController::class, 'destroyIp'])->scopeBindings();
            Route::post('accounts/{account}/ledger', [AccountsController::class, 'storeLedgerEntry']);
        });

        Route::middleware($can('rates:read'))->group(function () {
            Route::get('rate-decks', [RateDecksController::class, 'index']);
            Route::get('rate-decks/{rate_deck}', [RateDecksController::class, 'show']);
            Route::get('rate-decks/{rate_deck}/rates', [RateDecksController::class, 'rates']);
            Route::get('rate-decks/{rate_deck}/imports/{import}', [RateDecksController::class, 'showImport'])->scopeBindings();
        });
        Route::middleware($can('rates:write'))->group(function () {
            Route::post('rate-decks', [RateDecksController::class, 'store']);
            Route::patch('rate-decks/{rate_deck}', [RateDecksController::class, 'update']);
            Route::post('rate-decks/{rate_deck}/imports', [RateDecksController::class, 'storeImport']);
        });

        Route::middleware($can('routing:read'))->group(function () {
            Route::get('trunks', [RoutingController::class, 'trunks']);
            Route::get('trunks/{trunk}', [RoutingController::class, 'showTrunk']);
            Route::get('routing-plans', [RoutingController::class, 'plans']);
            Route::get('routing-plans/{routing_plan}', [RoutingController::class, 'showPlan']);
            Route::post('route-test', [RoutingController::class, 'routeTest']);
            Route::get('quality', [QualityController::class, 'api']);
        });
        Route::middleware($can('routing:write'))->scopeBindings()->group(function () {
            Route::post('trunks', [RoutingController::class, 'storeTrunk']);
            Route::patch('trunks/{trunk}', [RoutingController::class, 'updateTrunk']);
            Route::post('trunks/{trunk}/gateways', [RoutingController::class, 'storeGateway']);
            Route::patch('trunks/{trunk}/gateways/{gateway}', [RoutingController::class, 'updateGateway']);
            Route::delete('trunks/{trunk}/gateways/{gateway}', [RoutingController::class, 'destroyGateway']);
            Route::post('routing-plans', [RoutingController::class, 'storePlan']);
            Route::patch('routing-plans/{routing_plan}', [RoutingController::class, 'updatePlan']);
            Route::post('routing-plans/{routing_plan}/routes', [RoutingController::class, 'storeRoute']);
            Route::patch('routing-plans/{routing_plan}/routes/{route}', [RoutingController::class, 'updateRoute']);
            Route::delete('routing-plans/{routing_plan}/routes/{route}', [RoutingController::class, 'destroyRoute']);
        });

        Route::middleware($can('cdrs:read'))->group(function () {
            Route::get('cdrs', [CdrsController::class, 'index']);
            Route::get('cdrs/summary', [CdrsController::class, 'summary']);
            Route::get('cdrs/{cdr}', [CdrsController::class, 'show'])->whereNumber('cdr');
        });
        Route::middleware($can('billing:read'))->group(function () {
            Route::get('invoices', [CdrsController::class, 'invoices']);
            Route::get('invoices/{invoice}', [CdrsController::class, 'invoice']);
        });
    });
});
