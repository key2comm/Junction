<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AccountIpController;
use App\Http\Controllers\Admin\AlertController;
use App\Http\Controllers\Admin\CdrController;
use App\Http\Controllers\Admin\DidController;
use App\Http\Controllers\Admin\EndpointController;
use App\Http\Controllers\Admin\GatewayController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\LedgerEntryController;
use App\Http\Controllers\Admin\LiveCallsController;
use App\Http\Controllers\Admin\PbxController;
use App\Http\Controllers\Admin\PortalUserController;
use App\Http\Controllers\Admin\QualityController;
use App\Http\Controllers\Admin\RateController;
use App\Http\Controllers\Admin\RateDeckController;
use App\Http\Controllers\Admin\RateImportController;
use App\Http\Controllers\Admin\TariffController;
use App\Http\Controllers\Admin\RoutingPlanController;
use App\Http\Controllers\Admin\TrunkController;
use App\Http\Controllers\Admin\VendorBillingController;
use Illuminate\Support\Facades\Route;

/*
 * Softswitch administration: admin users only (the "admin" middleware sends
 * customer portal users to /portal). Every admin can manage everything.
 */
Route::middleware(['auth', 'admin'])->group(function () {
    // Routing → Carriers: the carrier accounts (Billing → Accounts lists everyone and creates customers).
    Route::get('carriers', [AccountController::class, 'index'])->defaults('scope', 'carrier')->name('carriers.index');
    Route::get('carriers/create', [AccountController::class, 'create'])->defaults('scope', 'carrier')->name('carriers.create');
    Route::resource('accounts', AccountController::class)->except(['destroy']);

    Route::scopeBindings()->group(function () {
        Route::post('accounts/{account}/ips', [AccountIpController::class, 'store'])->name('accounts.ips.store');
        Route::patch('accounts/{account}/ips/{ip}', [AccountIpController::class, 'update'])->name('accounts.ips.update');
        Route::delete('accounts/{account}/ips/{ip}', [AccountIpController::class, 'destroy'])->name('accounts.ips.destroy');
    });

    Route::post('accounts/{account}/portal-users', [PortalUserController::class, 'store'])->middleware('throttle:20,1')->name('accounts.portal-users.store');
    Route::delete('accounts/{account}/portal-users/{user}', [PortalUserController::class, 'destroy'])->name('accounts.portal-users.destroy');

    Route::get('accounts/{account}/route-test', [AccountController::class, 'routeTest'])
        ->middleware('throttle:60,1')
        ->name('accounts.route-test');

    Route::post('accounts/{account}/ledger', [LedgerEntryController::class, 'store'])
        ->middleware(['billing', 'throttle:30,1'])
        ->name('accounts.ledger.store');

    // PBX of a customer account
    Route::get('pbx', [PbxController::class, 'index'])->name('pbx.index');
    Route::scopeBindings()->prefix('accounts/{account}/pbx')->name('accounts.pbx.')->controller(PbxController::class)->group(function () {
        Route::get('/', 'show')->name('show');
        Route::get('phone', 'phone')->name('phone');
        Route::post('extensions', 'storeExtension')->name('extensions.store');
        Route::get('extensions/{pbxExtension}/edit', 'editExtension')->name('extensions.edit');
        Route::put('extensions/{pbxExtension}', 'updateExtension')->name('extensions.update');
        Route::delete('extensions/{pbxExtension}', 'destroyExtension')->name('extensions.destroy');
        Route::post('ivrs', 'storeIvr')->name('ivrs.store');
        Route::get('ivrs/{pbxIvr}/edit', 'editIvr')->name('ivrs.edit');
        Route::post('ivrs/{pbxIvr}', 'updateIvr')->name('ivrs.update'); // POST: carries a file upload
        Route::get('ivrs/{pbxIvr}/greeting', 'greeting')->name('ivrs.greeting');
        Route::delete('ivrs/{pbxIvr}', 'destroyIvr')->name('ivrs.destroy');
    });

    Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::put('alerts/rules/{type}', [AlertController::class, 'updateRule'])->name('alerts.rules.update');
    Route::post('alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge'])->name('alerts.acknowledge');
    Route::post('alerts/{alert}/resolve', [AlertController::class, 'resolve'])->name('alerts.resolve');

    Route::get('quality', [QualityController::class, 'index'])->name('quality.index');
    Route::get('live', [LiveCallsController::class, 'index'])->name('live.index');
    Route::post('live/{uuid}/hangup', [LiveCallsController::class, 'hangup'])->middleware('throttle:30,1')->name('live.hangup');

    Route::get('cdrs', [CdrController::class, 'index'])->name('cdrs.index');
    Route::get('cdrs/export', [CdrController::class, 'export'])->middleware('throttle:10,1')->name('cdrs.download');
    Route::get('cdrs/{cdr}', [CdrController::class, 'show'])->whereNumber('cdr')->name('cdrs.show');

    // Billing (unavailable while it is turned off in Settings → Billing).
    Route::middleware('billing')->group(function () {
        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('invoices/{invoice}/csv', [InvoiceController::class, 'csv'])->name('invoices.csv');
        Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
        Route::post('invoices/{invoice}/email', [InvoiceController::class, 'email'])->middleware('throttle:10,1')->name('invoices.email');
        Route::post('accounts/{account}/invoices', [InvoiceController::class, 'store'])->middleware('throttle:20,1')->name('accounts.invoices.store');

        // Vendor payables and vendor invoice reconciliation
        Route::post('accounts/{account}/vendor-ledger', [VendorBillingController::class, 'storeEntry'])->middleware('throttle:30,1')->name('accounts.vendor-ledger.store');
        Route::post('accounts/{account}/vendor-invoices', [VendorBillingController::class, 'storeInvoice'])->middleware('throttle:20,1')->name('accounts.vendor-invoices.store');
        Route::get('vendor-invoices/{vendor_invoice}', [VendorBillingController::class, 'showInvoice'])->name('vendor-invoices.show');
        Route::post('vendor-invoices/{vendor_invoice}/accept', [VendorBillingController::class, 'accept'])->name('vendor-invoices.accept');
        Route::post('vendor-invoices/{vendor_invoice}/dispute', [VendorBillingController::class, 'dispute'])->name('vendor-invoices.dispute');
        Route::post('vendor-invoices/{vendor_invoice}/refresh', [VendorBillingController::class, 'refresh'])->name('vendor-invoices.refresh');
    });

    // Rate decks, listed by kind: tariffs (customers) and carrier rates (trunks).
    Route::get('tariffs', [RateDeckController::class, 'index'])->defaults('kind', 'tariff')->name('tariffs.index');
    Route::get('carrier-rates', [RateDeckController::class, 'index'])->defaults('kind', 'carrier')->name('carrier-rates.index');
    // Old /rate-decks links show carrier rates. GET only: Route::redirect() would match every
    // method and swallow the create form's POST /rate-decks once routes are cached.
    Route::get('rate-decks', [RateDeckController::class, 'index'])->defaults('kind', 'carrier');
    Route::resource('rate-decks', RateDeckController::class)->except(['index', 'destroy']);
    Route::post('rate-decks/{rate_deck}/imports', [RateImportController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('rate-decks.imports.store');
    Route::scopeBindings()->group(function () {
        Route::post('rate-decks/{rate_deck}/imports/{import}/start', [RateImportController::class, 'start'])->name('rate-decks.imports.start');
        Route::delete('rate-decks/{rate_deck}/imports/{import}', [RateImportController::class, 'cancel'])->name('rate-decks.imports.cancel');
    });
    // Tariffs: build from Carrier Rates plus a markup, and adjust single rates by hand.
    Route::post('rate-decks/{rate_deck}/build', [TariffController::class, 'build'])->middleware('throttle:10,1')->name('rate-decks.build');
    Route::post('rate-decks/{rate_deck}/rates', [RateController::class, 'store'])->name('rate-decks.rates.store');
    Route::delete('rate-decks/{rate_deck}/rates', [RateDeckController::class, 'clearRates'])->name('rate-decks.rates.clear');
    Route::patch('rate-decks/{rate_deck}/rates/{rate}', [TariffController::class, 'updateRate'])->scopeBindings()->name('rate-decks.rates.update');

    Route::resource('trunks', TrunkController::class);
    // Routing -> PBX/Devices: where customers' calls are delivered.
    Route::resource('endpoints', EndpointController::class)->except(['show']);
    // Routing -> DIDs: numbers providers deliver to us, and where each one goes.
    Route::resource('dids', DidController::class)->except(['show']);
    Route::resource('trunks.gateways', GatewayController::class)->only(['store', 'edit', 'update', 'destroy'])->scoped();

    Route::resource('routing-plans', RoutingPlanController::class)->except(['destroy']);
    Route::scopeBindings()->group(function () {
        Route::post('routing-plans/{routing_plan}/routes', [RoutingPlanController::class, 'storeRoute'])->name('routing-plans.routes.store');
        Route::patch('routing-plans/{routing_plan}/routes/{route}', [RoutingPlanController::class, 'updateRoute'])->name('routing-plans.routes.update');
        Route::delete('routing-plans/{routing_plan}/routes/{route}', [RoutingPlanController::class, 'destroyRoute'])->name('routing-plans.routes.destroy');
    });
});
