<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Customer portal only (users linked to an account). Admins go to the dashboard. */
class EnsureCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user?->account_id) {
            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}
