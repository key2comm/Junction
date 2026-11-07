<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: config('app.name').': test email');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.test');
    }
}
