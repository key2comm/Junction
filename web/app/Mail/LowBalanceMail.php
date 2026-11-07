<?php

namespace App\Mail;

use App\Models\Account;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class LowBalanceMail extends Mailable
{
    public function __construct(public Account $account, public string $available, public string $threshold) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Low balance on account {$this->account->code}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.low-balance', with: [
            'available' => number_format((float) $this->available, 2).' '.$this->account->currency,
            'threshold' => number_format((float) $this->threshold, 2).' '.$this->account->currency,
            'prepaid' => $this->account->billing_type->value === 'prepaid',
        ]);
    }
}
