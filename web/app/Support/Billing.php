<?php

namespace App\Support;

/**
 * Settings → Billing. The built-in billing platform (balances and funds checks,
 * payments, invoices, low-balance alerts, vendor payables) can be turned off
 * when the switch only routes calls and another system bills the customers.
 * Rate decks are still used either way: buy rates for least-cost routing, sell
 * rates for which destinations a customer may call and to protect the margin.
 *
 * The routing engine reads the same setting (softswitch_billing_enabled()).
 */
final class Billing
{
    public const ENABLED = 'billing.enabled';

    public const AUTO_EMAIL = 'mail.auto_invoices'; // key kept from when it lived on the Email page

    public const DUE_DAYS = 'billing.invoice_due_days';

    public static function enabled(): bool
    {
        return (bool) Settings::get(self::ENABLED, true);
    }

    public static function autoEmailInvoices(): bool
    {
        return (bool) Settings::get(self::AUTO_EMAIL, false);
    }

    /** Days a postpaid customer has to pay an invoice. */
    public static function dueDays(): int
    {
        return (int) Settings::get(self::DUE_DAYS, config('softswitch.invoice_due_days', 15));
    }
}
