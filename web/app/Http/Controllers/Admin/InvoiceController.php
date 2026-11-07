<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\EmailInvoice;
use App\Models\Account;
use App\Models\Invoice;
use App\Services\InvoiceGenerator;
use App\Services\MailSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'account' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:issued,void'],
        ]);

        return Inertia::render('invoices/Index', [
            'invoices' => Invoice::query()
                ->when($filters['account'] ?? null, fn ($q, $id) => $q->where('account_id', $id))
                ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->orderByDesc('period_start')->orderByDesc('id')
                ->paginate(50)->withQueryString()
                ->through(fn (Invoice $i) => $i->only(['id', 'number', 'account_id', 'account_code', 'account_name', 'status',
                    'period_start', 'period_end', 'timezone', 'currency', 'usage_total', 'closing_balance', 'amount_due', 'issued_at', 'due_at'])),
            'filters' => (object) $filters,
            'customers' => Account::query()->customers()->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function show(Invoice $invoice): Response
    {
        return Inertia::render('invoices/Show', [
            'invoice' => $invoice->only(['id', 'number', 'account_id', 'account_code', 'account_name', 'billing_email', 'billing_type',
                'status', 'period_start', 'period_end', 'timezone', 'currency', 'opening_balance', 'usage_total', 'payments_total',
                'adjustments_total', 'closing_balance', 'amount_due', 'calls', 'billsec', 'issued_at', 'due_at', 'void_reason', 'voided_at',
                'emailed_at', 'emailed_to']),
            'mailConfigured' => MailSettings::configured(),
            'lines' => $invoice->lines()->get(['position', 'prefix', 'destination', 'calls', 'billsec', 'rate', 'amount']),
            'issuer' => ['name' => config('app.name'), 'host' => parse_url((string) config('app.url'), PHP_URL_HOST)],
        ]);
    }

    /** Issue an invoice for one account and month (e.g. after voiding one). */
    public function store(Request $request, Account $account, InvoiceGenerator $generator): RedirectResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);
        [$start, $end] = InvoiceGenerator::monthPeriod($account, CarbonImmutable::parse($data['month'].'-01 12:00', $account->timezone));

        try {
            $invoice = $generator->generate($account, $start, $end);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['month' => $e->getMessage()]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => "Issued {$invoice->number}."]);

        return to_route('invoices.show', $invoice);
    }

    public function void(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        if ($invoice->status === 'void') {
            throw ValidationException::withMessages(['reason' => 'This invoice is already void.']);
        }
        $invoice->update(['status' => 'void', 'void_reason' => $data['reason'], 'voided_at' => now()]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$invoice->number} voided. You can issue a new one for this period."]);

        return to_route('invoices.show', $invoice);
    }

    public function email(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate(['to' => ['nullable', 'email', 'max:255']]);
        $to = $data['to'] ?? $invoice->billing_email;
        if (! MailSettings::configured()) {
            throw ValidationException::withMessages(['to' => 'Set up email in Settings → Email first.']);
        }
        if (! $to) {
            throw ValidationException::withMessages(['to' => 'This customer has no billing email; enter an address.']);
        }
        EmailInvoice::dispatch($invoice, $to);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Sending {$invoice->number} to {$to}…"]);

        return to_route('invoices.show', $invoice);
    }

    public function csv(Invoice $invoice): StreamedResponse
    {
        return response()->streamDownload(function () use ($invoice) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['invoice', 'account', 'period_start', 'period_end', 'prefix', 'destination', 'calls', 'minutes', 'rate_per_min', 'amount', 'currency'], escape: '');
            foreach ($invoice->lines()->get() as $l) {
                fputcsv($out, array_map([CdrController::class, 'cell'], [
                    $invoice->number, $invoice->account_code, $invoice->period_start->toIso8601String(), $invoice->period_end->toIso8601String(),
                    $l->prefix, $l->destination, $l->calls, round($l->billsec / 60, 2), $l->rate, $l->amount, $invoice->currency,
                ]), escape: '');
            }
            fclose($out);
        }, "{$invoice->number}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
