<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /** The calling token: who it acts for and what it may do. */
    public function __invoke(Request $request): JsonResponse
    {
        $token = ApiAuth::token($request);

        return response()->json(['data' => [
            'token' => ['id' => $token->id, 'name' => $token->name, 'prefix' => $token->token_prefix, 'abilities' => $token->abilities,
                'expires_at' => $token->expires_at?->utc()->format('Y-m-d\TH:i:s\Z')],
            'customer' => $token->account ? Present::account($token->account, true) : null,
        ]]);
    }
}
