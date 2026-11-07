<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token authentication for /api/v1. With an ability argument
 * (api.auth:cdrs:read) the token must also hold that ability.
 */
class ApiAuth
{
    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        $token = $request->attributes->get('api_token');
        if (! $token) {
            $plain = (string) $request->bearerToken();
            $token = $plain !== '' ? ApiToken::findByPlaintext($plain) : null;
            if (! $token || ! $token->usable()) {
                return response()->json(['message' => 'Invalid, expired or revoked API token.'], 401, ['WWW-Authenticate' => 'Bearer']);
            }
            $request->attributes->set('api_token', $token);
            $request->setUserResolver(fn () => $token->user);

            // Record use at most once a minute (avoids a write per request).
            if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
                $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
            }
        }

        if ($ability !== null && ! $token->can($ability)) {
            return response()->json(['message' => "This token does not have the {$ability} ability."], 403);
        }

        return $next($request);
    }

    public static function token(Request $request): ApiToken
    {
        return $request->attributes->get('api_token');
    }

    /** The customer account a scoped token is limited to, or null for admin tokens. */
    public static function scopedAccountId(Request $request): ?int
    {
        return self::token($request)->account_id;
    }

    /** Admin-only endpoints refuse customer-scoped tokens. */
    public static function requireAdmin(Request $request): void
    {
        abort_if(self::scopedAccountId($request) !== null, 403, 'Customer tokens cannot use this endpoint.');
    }
}
