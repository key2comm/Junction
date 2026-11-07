<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\VendorInvoice;
use App\Services\VendorLedger;
use App\Services\VendorReconciliation;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * Vendor payables: payments we make, adjustments, and invoices received from
 * vendors reconciled against our CDRs.
 */
class VendorBillingController extends Controller
{
    /** Record a payment to the vendor (entered as a positive amount) or an adjustment (±). */
    public function storeEntry(Request $request, Account $account, VendorLedger $ledger): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:payment,adjustment'],
            'amount' => ['required', 'numeric', 'decimal:0,6', 'not_in:0', 'min:-10000000', 'max:10000000'],
            'description' => ['required', 'string', 'max:255'],
        ]);
        $amount = (string) $data['amount'];
        if ($data['type'] === 'payment') {
            if (bccomp($amount, '0', 6) < 0) {
                throw ValidationException::withMessages(['amount' => 'Enter the payment as a positive amount.']);
            }
            $amount = bcmul($amount, '-1', 6); // a payment reduces what we owe
        }

        try {
            $ledger->post($account, $data['type'], $amount, $data['description'], user: $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => ucfirst($data['type']).' recorded.']);

        return to_route('accounts.show', $account);
    }

    public function storeInvoice(Request $request, Account $account, VendorReconciliation $reconciliation): RedirectResponse
    {
        abort_unless($account->is_vendor, 404);
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:64', Rule::unique('vendor_invoices')->where('account_id', $account->id)],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'timezone' => ['required', 'timezone:all'],
            'amount' => ['required', 'numeric', 'decimal:0,6', 'min:0', 'max:100000000'],
            'calls' => ['nullable', 'integer', 'min:0'],
            'minutes' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'breakdown' => ['nullable', 'file', 'mimes:csv,txt', 'max:10240'],
        ], ['reference.unique' => 'An invoice with this reference is already recorded for this vendor.']);

        // Dates are whole days in the vendor's billing time zone; the end date is inclusive.
        $start = CarbonImmutable::parse($data['period_start'], $data['timezone'])->startOfDay();
        $end = CarbonImmutable::parse($data['period_end'], $data['timezone'])->startOfDay()->addDay();
        if ($start->diffInDays($end) > 93) {
            throw ValidationException::withMessages(['period_end' => 'Invoice periods are limited to 3 months.']);
        }

        try {
            $invoice = $reconciliation->record($account, [
                'reference' => $data['reference'],
                'period_start' => $start->utc(),
                'period_end' => $end->utc(),
                'timezone' => $data['timezone'],
                'amount' => (string) $data['amount'],
                'calls' => $data['calls'] ?? null,
                'minutes' => isset($data['minutes']) ? (string) $data['minutes'] : null,
                'notes' => $data['notes'] ?? null,
            ], $request->file('breakdown'), $request->user());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['breakdown' => $e->getMessage()]);
        }

        return to_route('vendor-invoices.show', $invoice);
    }

    public function showInvoice(VendorInvoice $vendorInvoice, VendorReconciliation $reconciliation): Response
    {
        $vendorInvoice->load('account:id,code,name,currency,vendor_balance');

        return Inertia::render('vendor-invoices/Show', [
            'invoice' => [
                ...$vendorInvoice->only(['id', 'reference', 'period_start', 'period_end', 'timezone', 'currency', 'amount', 'calls', 'minutes',
                    'our_amount', 'our_calls', 'our_minutes', 'status', 'notes', 'filename', 'resolved_at', 'created_at']),
                'difference' => $vendorInvoice->difference(),
                'vendor' => $vendorInvoice->account->only(['id', 'code', 'name', 'vendor_balance']),
            ],
            'comparison' => $reconciliation->comparison($vendorInvoice),
            'has_breakdown' => $vendorInvoice->lines()->exists(),
        ]);
    }

    public function accept(Request $request, VendorInvoice $vendorInvoice, VendorReconciliation $reconciliation, VendorLedger $ledger): RedirectResponse
    {
        try {
            $reconciliation->accept($vendorInvoice, $ledger, $request->user());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => "Accepted {$vendorInvoice->reference}."]);

        return to_route('vendor-invoices.show', $vendorInvoice);
    }

    public function dispute(Request $request, VendorInvoice $vendorInvoice, VendorReconciliation $reconciliation): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);
        try {
            $reconciliation->dispute($vendorInvoice, $data['note']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['note' => $e->getMessage()]);
        }

        return to_route('vendor-invoices.show', $vendorInvoice);
    }

    public function refresh(VendorInvoice $vendorInvoice, VendorReconciliation $reconciliation): RedirectResponse
    {
        if ($vendorInvoice->status !== 'accepted') {
            $reconciliation->refresh($vendorInvoice);
        }

        return to_route('vendor-invoices.show', $vendorInvoice);
    }
}
