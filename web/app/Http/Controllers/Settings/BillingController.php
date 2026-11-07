<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\MailSettings;
use App\Support\Billing;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Billing: whether the switch bills customers itself, and how.
 * With billing off the switch only routes calls (rates are still used for
 * least-cost routing); another system sends the invoices.
 */
class BillingController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/Billing', [
            'settings' => [
                'enabled' => Billing::enabled(),
                'auto_email_invoices' => Billing::autoEmailInvoices(),
                'invoice_due_days' => Billing::dueDays(),
            ],
            'mailConfigured' => MailSettings::configured(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'auto_email_invoices' => ['nullable', 'boolean'],
            'invoice_due_days' => ['nullable', 'integer', 'between:0,365'],
        ]);
        $enabled = $request->boolean('enabled');
        $wasEnabled = Billing::enabled();

        $values = [Billing::ENABLED => $enabled];
        // The invoice options are only on the form while billing is on; keep them otherwise.
        if ($enabled && $request->has('invoice_due_days')) {
            $values[Billing::AUTO_EMAIL] = $request->boolean('auto_email_invoices');
            $values[Billing::DUE_DAYS] = (int) ($data['invoice_due_days'] ?? Billing::dueDays());
        }
        Settings::put($values);

        Inertia::flash('toast', ['type' => 'success', 'message' => match (true) {
            $enabled && ! $wasEnabled => 'Billing turned on. Funds checks apply to new calls from now on.',
            ! $enabled && $wasEnabled => 'Billing turned off. The switch now only routes calls.',
            default => 'Billing settings saved.',
        }]);

        return to_route('billing.edit');
    }
}
