<?php

namespace App\Mail;

use App\Models\Alert;
use App\Services\AlertEngine;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AlertMail extends Mailable
{
    public function __construct(public Alert $alert) {}

    public function envelope(): Envelope
    {
        $label = AlertEngine::RULES[$this->alert->type]['label'] ?? $this->alert->type;

        return new Envelope(subject: '['.strtoupper($this->alert->severity)."] {$label}: {$this->alert->title}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.alert', with: [
            'label' => AlertEngine::RULES[$this->alert->type]['label'] ?? $this->alert->type,
            'url' => route('alerts.index'),
        ]);
    }
}
