<?php

namespace App\Jobs;

use App\Mail\InvoiceMail;
use App\Models\Invoice;
use App\Services\MailSettings;
use App\Support\Billing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/** Emails an invoice to the customer's billing address (copy to the admin address). */
class EmailInvoice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 300;

    public function __construct(public Invoice $invoice, public ?string $to = null) {}

    public function handle(): void
    {
        if (! Billing::enabled()) {
            return; // turned off after this was queued: send nothing
        }
        if (! MailSettings::fresh()) {
            throw new RuntimeException('Email is not configured.');
        }
        $to = $this->to ?? $this->invoice->billing_email;
        if (! $to) {
            throw new RuntimeException("Invoice {$this->invoice->number} has no billing email.");
        }

        $mail = Mail::to($to);
        if (($copy = MailSettings::adminAddress()) && strcasecmp($copy, $to) !== 0) {
            $mail->bcc($copy);
        }
        $mail->send(new InvoiceMail($this->invoice));

        $this->invoice->forceFill(['emailed_at' => now(), 'emailed_to' => $to])->save();
    }
}
