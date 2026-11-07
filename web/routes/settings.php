<?php

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
    Route::get('settings/certificate', [CertificateController::class, 'edit'])->name('certificate.edit');
    Route::post('settings/certificate', [CertificateController::class, 'store'])
        ->middleware([RequirePassword::class, 'throttle:6,1'])
        ->name('certificate.store');
    // Outgoing email (SMTP) for invoices and alerts. Changing it requires a recent password confirmation.
    Route::get('settings/email', [EmailController::class, 'edit'])->name('email.edit');
    Route::put('settings/email', [EmailController::class, 'update'])->middleware(RequirePassword::class)->name('email.update');
    Route::post('settings/email/test', [EmailController::class, 'test'])->middleware('throttle:6,1')->name('email.test');

    Route::delete('settings/certificate', [CertificateController::class, 'destroy'])
        ->middleware(RequirePassword::class)
        ->name('certificate.destroy');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
