<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AccountIpController;
use App\Http\Controllers\Admin\CdrController;
use App\Http\Controllers\Admin\GatewayController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\LedgerEntryController;
use App\Http\Controllers\Admin\PortalUserController;
use App\Http\Controllers\Admin\RateDeckController;
use App\Http\Controllers\Admin\RateImportController;
use App\Http\Controllers\Admin\RoutingPlanController;
use App\Http\Controllers\Admin\TrunkController;
use App\Http\Controllers\Admin\VendorBillingController;
use Illuminate\Support\Facades\Route;

/*
 * Softswitch administration: admin users only (the "admin" middleware sends
 * customer portal users to /portal). Every admin can manage everything.
 */
Route::middleware(['auth', 'admin'])->group(function () {
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
        ->middleware('throttle:30,1')
        ->name('accounts.ledger.store');

    Route::get('cdrs', [CdrController::class, 'index'])->name('cdrs.index');
    Route::get('cdrs/export', [CdrController::class, 'export'])->middleware('throttle:10,1')->name('cdrs.download');
    Route::get('cdrs/{cdr}', [CdrController::class, 'show'])->whereNumber('cdr')->name('cdrs.show');

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
