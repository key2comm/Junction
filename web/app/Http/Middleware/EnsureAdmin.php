<?php

namespace App\Http\Middleware;

use App\Services\FirewallPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        return $next($request);
    }
}
