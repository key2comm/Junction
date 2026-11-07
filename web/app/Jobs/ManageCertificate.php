<?php

namespace App\Jobs;

use App\Services\CertificateManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs certificate actions in the queue worker, which (unlike PHP-FPM) is
 * allowed to call the root helper through sudo.
 */
class ManageCertificate implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(
        public string $action,          // status | issue | selfsigned
        public ?string $domain = null,
        public ?string $email = null,
    ) {}

    public function uniqueId(): string
    {
        return 'certificate';
    }

    public function handle(CertificateManager $certificates): void
    {
        $certificates->markTask('running', $this->action);

        try {
            match ($this->action) {
                'issue' => $certificates->issue((string) $this->domain, (string) $this->email),
                'selfsigned' => $certificates->useSelfSigned(),
                default => $certificates->refreshStatus(),
            };
            $certificates->markTask('done', $this->action);
        } catch (Throwable $e) {
            Log::warning('Certificate action failed', ['action' => $this->action, 'error' => $e->getMessage()]);
            $certificates->markTask('failed', $this->action, $e->getMessage());
        }
    }
}
