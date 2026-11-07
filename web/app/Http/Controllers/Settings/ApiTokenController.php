<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ApiToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Settings → API tokens. The plaintext of a new token is shown exactly once.
 */
class ApiTokenController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/ApiTokens', [
            'tokens' => ApiToken::query()->with(['account:id,code', 'user:id,name'])->orderByDesc('id')->get()
                ->map(fn (ApiToken $t) => [
                    ...$t->only(['id', 'name', 'token_prefix', 'abilities', 'last_used_at', 'last_used_ip', 'expires_at', 'revoked_at', 'created_at']),
                    'account' => $t->account?->code,
                    'created_by' => $t->user?->name,
                    'usable' => $t->usable(),
                ]),
            'abilities' => ApiToken::ABILITIES,
            'customerAbilities' => ApiToken::CUSTOMER_ABILITIES,
            'customers' => Account::query()->customers()->orderBy('code')->get(['id', 'code', 'name']),
            'docsUrl' => route('api.openapi'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(array_keys(ApiToken::ABILITIES))],
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('is_customer', true)],
            'expires_in_days' => ['nullable', 'integer', 'between:1,3650'],
        ]);

        try {
            [, $plain] = ApiToken::issue(
                $data['name'],
                $data['abilities'],
                $request->user(),
                isset($data['account_id']) ? Account::query()->find($data['account_id']) : null,
                isset($data['expires_in_days']) ? now()->addDays($data['expires_in_days']) : null,
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['abilities' => $e->getMessage()]);
        }

        // Shown once on the next page load; never stored in plaintext.
        Inertia::flash('newToken', $plain);

        return to_route('api-tokens.index');
    }

    public function destroy(ApiToken $apiToken): RedirectResponse
    {
        $apiToken->update(['revoked_at' => $apiToken->revoked_at ?? now()]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Revoked {$apiToken->name}."]);

        return to_route('api-tokens.index');
    }
}
