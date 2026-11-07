<?php

namespace App\Mail;

use App\Http\Controllers\Admin\CdrController;
use App\Models\Invoice;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InvoiceMail extends Mailable
{
    public function __construct(public Invoice $invoice) {}

    public function envelope(): Envelope
    {
        $kind = $this->invoice->billing_type === 'postpaid' ? 'Invoice' : 'Statement';
        $month = $this->invoice->period_start->setTimezone($this->invoice->timezone)->format('F Y');

        return new Envelope(subject: "{$kind} {$this->invoice->number} for {$month}");
    }

    public function content(): Content
    {
        $i = $this->invoice;
        $fmt = fn ($v) => number_format((float) $v, 2).' '.$i->currency;

        return new Content(markdown: 'mail.invoice', with: [
            'kind' => $i->billing_type === 'postpaid' ? 'invoice' : 'statement',
            'month' => $i->period_start->setTimezone($i->timezone)->format('F Y'),
            'lines' => $i->lines()->limit(25)->get(),
            'more' => max(0, $i->lines()->count() - 25),
            'fmt' => $fmt,
            'due' => $i->due_at?->setTimezone($i->timezone)->format('j F Y'),
        ]);
    }

    /** The full destination breakdown as CSV. */
    public function attachments(): array
    {
        $i = $this->invoice;

        return [Attachment::fromData(function () use ($i) {
            $out = fopen('php://temp', 'r+');
            fputcsv($out, ['prefix', 'destination', 'calls', 'minutes', 'rate_per_min', 'amount', 'currency'], escape: '');
            foreach ($i->lines()->get() as $l) {
                fputcsv($out, array_map([CdrController::class, 'cell'],
                    [$l->prefix, $l->destination, $l->calls, round($l->billsec / 60, 2), $l->rate, $l->amount, $i->currency]), escape: '');
            }
            rewind($out);

            return stream_get_contents($out);
        }, "{$i->number}.csv")->withMime('text/csv')];
    }
}
