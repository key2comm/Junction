<?php

namespace App\Http\Middleware;

use App\Support\Audit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Settings → Audit log: one row per change request by a signed-in user (see App\Support\Audit). */
class RecordAudit
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || Audit::skips($request)) {
            return $next($request);
        }
        Audit::begin();
        try {
            $response = $next($request);
        } catch (Throwable $e) {
            Audit::end();

            throw $e;
        }
        Audit::request($request, $response);

        return $response;
    }
}
