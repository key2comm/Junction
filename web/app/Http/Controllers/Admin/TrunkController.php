<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Trunk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A carrier's routing record. The UI edits it on the carrier form (see
 * AccountController); the REST API still exposes it as /trunks.
 */
class TrunkController extends Controller
{
    /** Old Routing -> Trunks link: trunks are part of the carrier now. */
    public function index(): RedirectResponse
    {
        return to_route('carriers.index');
    }

    /** A trunk link (CDRs, quality, LCR groups...) opens its carrier. */
    public function show(Trunk $trunk): RedirectResponse
    {
        return to_route('accounts.show', $trunk->account_id);
    }

    /** Also used by the REST API, so both validate identically. */
    public function validated(Request $request, ?Trunk $trunk = null): array
    {
        $request->merge(['enabled' => $request->boolean('enabled'), 'tech_prefix' => trim((string) $request->input('tech_prefix'))]);

        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('is_vendor', true)],
            'name' => ['required', 'string', 'max:255',
                Rule::unique('trunks', 'name')->where('account_id', $request->integer('account_id'))->ignore($trunk)],
            'rate_deck_id' => ['nullable', 'integer', Rule::exists('rate_decks', 'id')->where('kind', 'carrier')],
            'enabled' => ['boolean'],
            'max_channels' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_inbound' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_cps' => ['nullable', 'numeric', 'min:0.1', 'max:10000'],
            'tech_prefix' => ['nullable', 'string', 'max:16', 'regex:/^[0-9#*]*$/'],
            'strip_digits' => ['nullable', 'integer', 'min:0', 'max:15'],
        ], ['account_id.exists' => 'Choose an account marked as a carrier.', 'rate_deck_id.exists' => 'Choose carrier rates (not a customer tariff).']);

        return [...$data, 'tech_prefix' => $data['tech_prefix'] ?? '', 'strip_digits' => $data['strip_digits'] ?? 0];
    }
}
