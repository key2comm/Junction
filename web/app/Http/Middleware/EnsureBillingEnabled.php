<?php

namespace App\Http\Middleware;

use App\Support\Billing;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Billing pages and API endpoints (invoices, payments, vendor payables) are
 * unavailable while the built-in billing is turned off in Settings → Billing.
 */
class EnsureBillingEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Billing::enabled()) {
            return $next($request);
        }
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'Billing is turned off on this switch.'], 404);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => 'Billing is turned off on this switch.']);

        return redirect()->route($request->user()?->account_id ? 'portal.home' : 'dashboard');
    }
}
