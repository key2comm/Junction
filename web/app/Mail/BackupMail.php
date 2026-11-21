<?php

namespace App\Mail;

use App\Models\Backup;
use App\Services\ReportRunner;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Settings → Backups: the backup attached (when small enough), or what went wrong. */
class BackupMail extends Mailable
{
    public function __construct(public Backup $backup) {}

    private function attached(): bool
    {
        return $this->backup->status === 'done' && $this->backup->size <= ReportRunner::MAX_ATTACHMENT;
    }

    public function envelope(): Envelope
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: config('app.name');

        return new Envelope(subject: $this->backup->status === 'done'
            ? "Backup of {$host}: {$this->backup->filename}"
            : "Backup of {$host} FAILED");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.backup', with: [
            'attached' => $this->attached(),
            'url' => route('backups.edit'),
            'mb' => number_format(($this->backup->size ?? 0) / 1048576, 1),
        ]);
    }

    public function attachments(): array
    {
        return $this->attached() ? [Attachment::fromStorageDisk('local', $this->backup->path)->as($this->backup->filename)->withMime('application/octet-stream')] : [];
    }
}
