<?php

namespace App\Jobs;

use App\Mail\TestMail;
use App\Services\MailSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Sends a test message with the saved SMTP settings and records the outcome. */
class SendTestEmail implements ShouldQueue
{
    use Queueable;

    public const RESULT = 'softswitch.mail.test';

    public int $tries = 1;

    public function __construct(public string $to) {}

    public function handle(): void
    {
        $record = fn (string $state, ?string $message = null) => Cache::put(self::RESULT,
            ['state' => $state, 'to' => $this->to, 'message' => $message, 'at' => now()->toIso8601String()], now()->addDay());

        if (! MailSettings::fresh()) {
            $record('failed', 'Email is not configured (host and From address are required).');

            return;
        }
        try {
            Mail::to($this->to)->send(new TestMail);
            $record('sent');
        } catch (Throwable $e) {
            $record('failed', mb_substr($e->getMessage(), 0, 500));
        }
    }
}
