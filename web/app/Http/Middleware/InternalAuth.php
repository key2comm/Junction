<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards /internal/* (FreeSWITCH -> Laravel callbacks): loopback clients only,
 * with the shared HTTP Basic credentials the installer gives FreeSWITCH.
 */
class InternalAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $ip = (string) $request->server('REMOTE_ADDR');
        if (! in_array($ip, ['127.0.0.1', '::1'], true)) {
            abort(404);
        }

        $user = (string) config('softswitch.internal.user');
        $password = (string) config('softswitch.internal.password');
        if ($password === ''
            || ! hash_equals($user, (string) $request->getUser())
            || ! hash_equals($password, (string) $request->getPassword())) {
            return response('Unauthorized', 401, ['WWW-Authenticate' => 'Basic realm="softswitch-internal"']);
        }

        return $next($request);
    }
}
