<?php

namespace App\Mail;

use App\Models\ReportFile;
use App\Models\ReportSchedule;
use App\Services\ReportRunner;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Settings → Scheduled reports: the CSV attached (or, when too big, where to download it). */
class ScheduledReportMail extends Mailable
{
    public function __construct(public ReportSchedule $schedule, public ReportFile $file) {}

    private function attached(): bool
    {
        return $this->file->size <= ReportRunner::MAX_ATTACHMENT;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->schedule->name}: {$this->file->filename}");
    }

    public function content(): Content
    {
        $tz = $this->schedule->timezone ?: 'UTC';

        return new Content(markdown: 'mail.report', with: [
            'periodFrom' => $this->file->period_start->setTimezone($tz)->format('j M Y H:i'),
            'periodTo' => $this->file->period_end->setTimezone($tz)->format('j M Y H:i'),
            'tz' => $tz,
            'attached' => $this->attached(),
            'url' => route('reports.index'),
        ]);
    }

    public function attachments(): array
    {
        return $this->attached() ? [Attachment::fromStorageDisk('local', $this->file->path)->as($this->file->filename)->withMime('text/csv')] : [];
    }
}
