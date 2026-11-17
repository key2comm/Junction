<?php

namespace App\Http\Middleware;

use App\Services\HighAvailability;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the backup server (high availability) the database is a read-only copy,
 * so the admin UI, portal and API aren't available there: signing in and the
 * backup's own page (sync status, "Make this the main server") are.
 */
class EnsureNotStandby
{
    /** Routes that work on the backup. */
    private const ALLOWED = [
        'login', 'login.*', 'logout', 'password.confirm', 'password.confirm.*', 'password.confirmation',
        'two-factor.*', 'passkey*', 'passkeys.*', 'branding.show', 'ha.*', 'home',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! HighAvailability::isStandby() || Str::is(self::ALLOWED, (string) $request->route()?->getName())) {
            return $next($request);
        }

        $message = 'This is the backup server: its database is a read-only copy of the main server\'s.';
        if ($request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
            return response()->json(['message' => $message], 503);
        }
        if (! $request->user()) {
            return redirect()->guest(route('login'));
        }
        if (! $request->isMethodSafe()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);
        }

        return redirect()->route('ha.standby');
    }
}
