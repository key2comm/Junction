<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Settings\BrandingController;
use Illuminate\Support\Facades\Route;

// No public landing page: send people to the login, the admin dashboard or the
// customer portal ("dashboard" itself redirects portal users to /portal).
// Settings → Branding images: public, the sign-in page shows them too.
Route::get('branding/{kind}', [BrandingController::class, 'show'])->whereIn('kind', ['logo', 'icon'])->name('branding.show');

Route::get('/', fn () => to_route(auth()->check() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->middleware('admin')->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
require __DIR__.'/portal.php';
