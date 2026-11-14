<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Admin\CdrController;
use App\Http\Controllers\Admin\PbxController;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Cdr;
use App\Models\Invoice;
use App\Models\Rate;
use App\Support\Billing;
use App\Support\CallStats;
use App\Support\CdrFilters;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The customer portal. Every query here is scoped to the signed-in user's own
 * account, and nothing exposes carriers, buy costs or margins.
 */
class PortalController extends Controller
{
    private function account(Request $request): Account
    {
        return $request->user()->account()->firstOrFail();
    }

    public function overview(Request $request): Response
    {
        $account = $this->account($request);
        $now = CarbonImmutable::now('UTC');
        $summary = CdrController::summary(new CdrFilters($now->subDay(), $now, ['customer' => $account->id]));

        return Inertia::render('portal/Overview', [
            'account' => [
                ...$account->only(['code', 'name', 'currency', 'balance', 'credit_limit', 'timezone', 'low_balance_threshold', 'low_balance_alerted_at', 'max_channels', 'max_cps']),
                'status' => $account->status->value,
                'billing_type' => $account->billing_type->value,
                'available_now' => $account->availableFundsNow(),
            ],
            'last24h' => array_intersect_key($summary, array_flip(['calls', 'answered', 'rejected', 'asr', 'acd', 'minutes', 'sell'])),
            // activeCalls, today, month, week, hours: this customer's calls only, in the user's time zone.
            ...(new CallStats($request->user()->timezone ?: 'UTC', $account->id))->all(),
            // Not shown while billing is off (Settings → Billing).
            'payments' => Billing::enabled() ? $account->ledgerEntries()->whereIn('type', ['payment', 'adjustment'])->latest('id')->limit(10)
                ->get(['id', 'type', 'amount', 'description', 'effective_at']) : [],
            'invoices' => Billing::enabled() ? Invoice::query()->where('account_id', $account->id)->where('status', 'issued')->orderByDesc('period_start')->limit(3)
                ->get(['id', 'number', 'period_start', 'timezone', 'currency', 'usage_total', 'amount_due', 'due_at']) : [],
        ]);
    }

    /** Browser phone (WebRTC) for one of the account's PBX extensions. */
    public function phone(Request $request): Response
    {
        return Inertia::render('pbx/Phone', PbxController::phoneProps($this->account($request), $request->integer('extension') ?: null, admin: false));
    }

    public function calls(Request $request): Response
    {
        $account = $this->account($request);
        $filters = $this->filters($request, $account);

        $calls = $filters->apply(Cdr::query())->orderByDesc('started_at')->orderByDesc('id')
            ->simplePaginate(50)->withQueryString()
            ->through(fn (Cdr $c) => [
                ...$c->only(['id', 'started_at', 'caller', 'callee', 'destination', 'billsec', 'sip_code', 'sell_destination', 'sell_rate', 'sell_cost']),
                'answered' => $c->answered_at !== null,
                'rejected' => $c->answered_at === null && $c->attempts === 0,
                'reason' => $c->answered_at === null ? $c->hangup_cause : null,
            ]);
        $summary = CdrController::summary($filters);

        return Inertia::render('portal/Calls', [
            'calls' => $calls,
            'summary' => array_intersect_key($summary, array_flip(['calls', 'answered', 'asr', 'acd', 'minutes', 'sell'])),
            'filters' => $filters->query(),
            'currency' => $account->currency,
        ]);
    }

    public function exportCalls(Request $request): StreamedResponse
    {
        $account = $this->account($request);
        $filters = $this->filters($request, $account);

        return response()->streamDownload(function () use ($filters, $account) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['started_at_utc', 'answered_at_utc', 'ended_at_utc', 'caller', 'callee', 'destination', 'billsec', 'sip_code',
                'result', 'rated_prefix', 'rated_destination', 'rate_per_min', 'cost', 'currency', 'uuid'], escape: '');
            $filters->apply(Cdr::query())->chunkById(2000, function ($rows) use ($out, $account) {
                foreach ($rows as $c) {
                    fputcsv($out, array_map([CdrController::class, 'cell'], [
                        $c->started_at?->utc()->toIso8601String(), $c->answered_at?->utc()->toIso8601String(), $c->ended_at?->utc()->toIso8601String(),
                        $c->caller, $c->callee, $c->destination, $c->billsec, $c->sip_code,
                        $c->answered_at ? 'answered' : ($c->hangup_cause ?? 'failed'),
                        $c->sell_prefix, $c->sell_destination, $c->sell_rate, $c->sell_cost, $account->currency, $c->uuid,
                    ]), escape: '');
                }
                flush();
            });
            fclose($out);
        }, sprintf('calls-%s-%s.csv', $account->code, $filters->from->format('Ymd')), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function invoices(Request $request): Response
    {
        $account = $this->account($request);

        return Inertia::render('portal/Invoices', [
            'invoices' => Invoice::query()->where('account_id', $account->id)->where('status', 'issued')
                ->orderByDesc('period_start')->paginate(24)
                ->through(fn (Invoice $i) => $i->only(['id', 'number', 'period_start', 'timezone', 'currency', 'usage_total', 'closing_balance', 'amount_due', 'issued_at', 'due_at'])),
        ]);
    }

    public function invoice(Request $request, Invoice $invoice): Response
    {
        abort_unless($invoice->account_id === $request->user()->account_id && $invoice->status === 'issued', 404);

        return Inertia::render('portal/Invoice', [
            'invoice' => $invoice->only(['id', 'number', 'account_code', 'account_name', 'billing_email', 'billing_type', 'status', 'period_start',
                'period_end', 'timezone', 'currency', 'opening_balance', 'usage_total', 'payments_total', 'adjustments_total', 'charges_total',
                'included_seconds', 'closing_balance', 'amount_due', 'calls', 'billsec', 'issued_at', 'due_at', 'void_reason', 'voided_at']),
            'charges' => $invoice->charges()->get(['position', 'description', 'quantity', 'unit_price', 'amount']),
            'lines' => $invoice->lines()->get(['position', 'prefix', 'destination', 'calls', 'billsec', 'rate', 'amount']),
            'issuer' => ['name' => config('app.name'), 'host' => parse_url((string) config('app.url'), PHP_URL_HOST)],
        ]);
    }

    public function invoiceCsv(Request $request, Invoice $invoice): StreamedResponse
    {
        abort_unless($invoice->account_id === $request->user()->account_id && $invoice->status === 'issued', 404);

        return app(\App\Http\Controllers\Admin\InvoiceController::class)->csv($invoice);
    }

    /** The customer's sell rates in effect now. */
    public function rates(Request $request): Response
    {
        $account = $this->account($request);
        $q = preg_replace('/\D/', '', (string) $request->query('q', ''));

        return Inertia::render('portal/Rates', [
            'rates' => $account->sell_rate_deck_id
                ? $this->currentRates($account)->when($q !== '', fn ($query) => $query->where('prefix', 'like', "{$q}%"))
                    ->orderBy('prefix')->paginate(100)->withQueryString()
                    ->through(fn (Rate $r) => $r->only(['prefix', 'destination', 'rate', 'connect_fee', 'initial_increment', 'increment', 'effective_from', 'effective_to']))
                : null,
            'scheduled' => $account->sell_rate_deck_id
                ? Rate::query()->where('rate_deck_id', $account->sell_rate_deck_id)->where('effective_from', '>', now())->count()
                : 0,
            'filters' => ['q' => $q],
            'currency' => $account->currency,
        ]);
    }

    public function ratesCsv(Request $request): StreamedResponse
    {
        $account = $this->account($request);
        abort_unless($account->sell_rate_deck_id, 404);

        return response()->streamDownload(function () use ($account) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['prefix', 'destination', 'rate_per_min', 'connect_fee', 'initial_increment', 'increment', 'effective_from', 'effective_to', 'currency'], escape: '');
            // Current and scheduled rates, so customers see upcoming changes.
            Rate::query()->where('rate_deck_id', $account->sell_rate_deck_id)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()))
                ->orderBy('prefix')->orderBy('effective_from')
                ->chunk(5000, function ($rows) use ($out, $account) {
                    foreach ($rows as $r) {
                        fputcsv($out, array_map([CdrController::class, 'cell'], [$r->prefix, $r->destination, $r->rate, $r->connect_fee,
                            $r->initial_increment, $r->increment, $r->effective_from?->utc()->toIso8601String(), $r->effective_to?->utc()->toIso8601String(),
                            $account->currency]), escape: '');
                    }
                });
            fclose($out);
        }, "rates-{$account->code}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // --- the customer's own API tokens (always scoped to their account) ---

    public function tokens(Request $request): Response
    {
        $account = $this->account($request);

        return Inertia::render('portal/ApiTokens', [
            'tokens' => ApiToken::query()->where('account_id', $account->id)->orderByDesc('id')->get()
                ->map(fn (ApiToken $t) => [...$t->only(['id', 'name', 'token_prefix', 'abilities', 'last_used_at', 'expires_at', 'revoked_at', 'created_at']), 'usable' => $t->usable()]),
            'abilities' => array_intersect_key(ApiToken::ABILITIES, array_flip(ApiToken::CUSTOMER_ABILITIES)),
            'docsUrl' => route('api.openapi'),
        ]);
    }

    public function storeToken(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(ApiToken::CUSTOMER_ABILITIES)],
            'expires_in_days' => ['nullable', 'integer', 'between:1,3650'],
        ]);
        try {
            [, $plain] = ApiToken::issue($data['name'], $data['abilities'], $request->user(), $this->account($request),
                isset($data['expires_in_days']) ? now()->addDays($data['expires_in_days']) : null);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['abilities' => $e->getMessage()]);
        }
        Inertia::flash('newToken', $plain);

        return to_route('portal.api-tokens.index');
    }

    public function destroyToken(Request $request, ApiToken $apiToken): RedirectResponse
    {
        abort_unless($apiToken->account_id === $request->user()->account_id, 404);
        $apiToken->update(['revoked_at' => $apiToken->revoked_at ?? now()]);

        return to_route('portal.api-tokens.index');
    }

    private function currentRates(Account $account)
    {
        return Rate::query()->where('rate_deck_id', $account->sell_rate_deck_id)->effectiveAt(now());
    }

    /** Call filters, always pinned to this customer. */
    private function filters(Request $request, Account $account): CdrFilters
    {
        $request->merge(['customer' => $account->id, 'vendor' => null, 'trunk' => null]);

        return CdrFilters::fromRequest($request);
    }
}
