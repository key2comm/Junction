<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\AccountIpController;
use App\Http\Controllers\Admin\LedgerEntryController;
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

    Route::post('accounts/{account}/ledger', [LedgerEntryController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('accounts.ledger.store');
});
