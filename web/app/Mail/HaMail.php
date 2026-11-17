<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** High availability notices from the backup server. */
class HaMail extends Mailable
{
    /** @param list<string> $lines */
    public function __construct(public string $title, public array $lines, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[{$this->host()}] {$this->title}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.ha', with: ['host' => $this->host()]);
    }

    private function host(): string
    {
        return (string) (gethostname() ?: config('app.name'));
    }
}
