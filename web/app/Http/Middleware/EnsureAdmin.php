<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin area only. Customer portal users are sent to the portal (or refused,
 * for non-page requests).
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

        return $next($request);
    }
}
