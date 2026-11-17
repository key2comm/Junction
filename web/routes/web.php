<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\HighAvailabilityController;
use Illuminate\Auth\Middleware\RequirePassword;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Settings\BrandingController;
use Illuminate\Support\Facades\Route;

// No public landing page: send people to the login, the admin dashboard or the
// customer portal ("dashboard" itself redirects portal users to /portal).
// Settings → Branding images: public, the sign-in page shows them too.
Route::get('branding/{kind}', [BrandingController::class, 'show'])->whereIn('kind', ['logo', 'icon'])->name('branding.show');

// The link in an admin invitation (Settings → Admin users): choose a name and password.
Route::get('invite/{token}', [InvitationController::class, 'show'])->name('invitation.show');
Route::post('invite/{token}', [InvitationController::class, 'accept'])->middleware('throttle:10,1')->name('invitation.accept');

Route::get('/', fn () => to_route(auth()->check() ? 'dashboard' : 'login'))->name('home');

// High availability: the backup server's page, and "Make this the main server"
// (Owners and Admins, with a recent password confirmation).
Route::get('backup-server', [HighAvailabilityController::class, 'standby'])->middleware('auth')->name('ha.standby');
Route::post('backup-server/promote', [HighAvailabilityController::class, 'promote'])
    ->middleware(['auth', 'admin', RequirePassword::class, 'throttle:3,1'])->name('ha.promote');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->middleware('admin')->name('dashboard');
    Route::post('dashboard/check-for-updates', [DashboardController::class, 'checkForUpdates'])->middleware(['admin', 'throttle:6,1'])->name('dashboard.check-updates');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
require __DIR__.'/portal.php';
