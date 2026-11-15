<?php

namespace App\Mail;

use App\Models\AdminInvitation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AdminInviteMail extends Mailable
{
    public function __construct(public AdminInvitation $invitation, public string $url, public string $inviter) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You're invited to manage ".config('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.admin-invite', with: [
            'role' => $this->invitation->role->label(),
            'description' => $this->invitation->role->description(),
            'host' => parse_url(config('app.url'), PHP_URL_HOST) ?: config('app.url'),
            'days' => AdminInvitation::DAYS,
        ]);
    }
}
