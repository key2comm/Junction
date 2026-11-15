<?php

namespace App\Http\Middleware;

use App\Services\FirewallPolicy;
use App\Support\AdminAreas;
use App\Support\AdminRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin area only. Customer portal users are sent to the portal (or refused,
 * for non-page requests). With Settings -> Firewall "Admin web login" locked
 * down, admins must come from a whitelisted address.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->account_id !== null) {
            if ($request->isMethod('GET') && ! $request->expectsJson()) {
                return redirect()->route('portal.home');
            }
            abort(403);
        }

        if ($user && FirewallPolicy::lockdown()['web'] && ! FirewallPolicy::isTrusted($request->ip())) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $message = "Admin access from your address ({$request->ip()}) isn't allowed: admin login is limited to the firewall whitelist.";
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                abort(403, $message);
            }

            return redirect()->route('login')->with('status', $message);
        }

        // Access level (Settings → Admin users): reading needs read access to the page's area, changing needs write.
        if ($user) {
            $area = AdminAreas::forRoute($request->route()?->getName());
            $access = $user->adminAccess($area);
            $writing = ! $request->isMethodSafe();
            if ($access === null || ($writing && $access !== AdminRole::WRITE)) {
                return $this->refuse($request, $user->adminRole(), $area, $access === null);
            }
        }

        return $next($request);
    }

    private function refuse(Request $request, AdminRole $role, string $area, bool $noAccess): Response
    {
        $message = $noAccess
            ? "Your access level ({$role->label()}) doesn't include ".AdminAreas::label($area).'.'
            : "Your access level ({$role->label()}) can see ".AdminAreas::label($area)." but can't change it.";
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(403, $message);
        }
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        // A page they can't open: back to the dashboard. A change they can't make: stay where they were.
        return $request->isMethodSafe() ? redirect()->route('dashboard') : back();
    }
}
