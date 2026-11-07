<?php

use App\Http\Controllers\Settings\ApiTokenController;
use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\CertificateController;
use App\Http\Controllers\Settings\EmailController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    // Server TLS certificate (Let's Encrypt). Changing it requires a recent password confirmation.
    Route::get('settings/certificate', [CertificateController::class, 'edit'])->middleware('admin')->name('certificate.edit');
    Route::post('settings/certificate', [CertificateController::class, 'store'])
        ->middleware([RequirePassword::class, 'throttle:6,1'])
        ->middleware('admin')->name('certificate.store');
    // REST API tokens. Creating and revoking require a recent password confirmation.
    Route::get('settings/api-tokens', [ApiTokenController::class, 'index'])->middleware('admin')->name('api-tokens.index');
    Route::post('settings/api-tokens', [ApiTokenController::class, 'store'])->middleware(RequirePassword::class)->middleware('admin')->name('api-tokens.store');
    Route::delete('settings/api-tokens/{api_token}', [ApiTokenController::class, 'destroy'])->middleware(RequirePassword::class)->middleware('admin')->name('api-tokens.destroy');

    // Outgoing email (SMTP) for invoices and alerts. Changing it requires a recent password confirmation.
    Route::get('settings/email', [EmailController::class, 'edit'])->middleware('admin')->name('email.edit');
    Route::put('settings/email', [EmailController::class, 'update'])->middleware(RequirePassword::class)->middleware('admin')->name('email.update');
    Route::post('settings/email/test', [EmailController::class, 'test'])->middleware('throttle:6,1')->middleware('admin')->name('email.test');

    // Built-in billing on/off and invoice options. Changing them requires a recent password confirmation.
    Route::get('settings/billing', [BillingController::class, 'edit'])->middleware('admin')->name('billing.edit');
    Route::put('settings/billing', [BillingController::class, 'update'])->middleware(RequirePassword::class)->middleware('admin')->name('billing.update');

    Route::delete('settings/certificate', [CertificateController::class, 'destroy'])
        ->middleware(RequirePassword::class)
        ->middleware('admin')->name('certificate.destroy');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
