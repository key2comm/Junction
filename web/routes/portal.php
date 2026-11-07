<?php

use App\Http\Controllers\Portal\PortalController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

/*
 * Customer portal: users linked to a customer account. Everything is scoped to
 * that account; admins are redirected to the dashboard.
 */
Route::middleware(['auth', 'customer'])->prefix('portal')->name('portal.')->group(function () {
    Route::get('/', [PortalController::class, 'overview'])->name('home');
    Route::get('calls', [PortalController::class, 'calls'])->name('calls.index');
    Route::get('calls/export', [PortalController::class, 'exportCalls'])->middleware('throttle:10,1')->name('calls.download');
    Route::get('invoices', [PortalController::class, 'invoices'])->name('invoices.index');
    Route::get('invoices/{invoice}', [PortalController::class, 'invoice'])->name('invoices.show');
    Route::get('invoices/{invoice}/csv', [PortalController::class, 'invoiceCsv'])->name('invoices.download');
    Route::get('rates', [PortalController::class, 'rates'])->name('rates.index');
    Route::get('rates/csv', [PortalController::class, 'ratesCsv'])->middleware('throttle:10,1')->name('rates.download');
    Route::get('api-tokens', [PortalController::class, 'tokens'])->name('api-tokens.index');
    Route::post('api-tokens', [PortalController::class, 'storeToken'])->middleware(RequirePassword::class)->name('api-tokens.store');
    Route::delete('api-tokens/{api_token}', [PortalController::class, 'destroyToken'])->middleware(RequirePassword::class)->name('api-tokens.destroy');
});
