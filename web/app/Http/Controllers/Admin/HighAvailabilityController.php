<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\HighAvailability;
use App\Support\AdminAreas;
use App\Support\AdminRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The backup server's only page: how in sync it is with the main server, and
 * (for Owners and Admins) the button that makes it the main server.
 */
class HighAvailabilityController extends Controller
{
    public function standby(Request $request): Response|RedirectResponse
    {
        if (! HighAvailability::isStandby()) {
            return redirect()->route('home');
        }
        $user = $request->user();

        return Inertia::render('ha/Standby', [
            'main' => HighAvailability::main(),
            'files' => HighAvailability::state('pull'),
            'promotion' => HighAvailability::promotionRequested() ? ['status' => 'requested'] : HighAvailability::state('promotion'),
            'canPromote' => $user->adminAccess(AdminAreas::SETTINGS) === AdminRole::WRITE,
            'maxLag' => config('softswitch.ha.max_lag'),
        ]);
    }

    /** "Make this the main server": the request is carried out as root by softswitch-ha-promote.path. */
    public function promote(Request $request): RedirectResponse
    {
        abort_unless(HighAvailability::isStandby(), 404);
        HighAvailability::requestPromotion($request->user()->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Making this the main server. It takes about a minute; you may need to sign in again.']);

        return redirect()->route('ha.standby');
    }
}
