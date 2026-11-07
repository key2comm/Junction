<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Manages the web server's TLS certificate through the root-owned
 * softswitch-cert helper. Only the queue worker calls the helper (never a web
 * request), and results are cached for the settings page to read.
 */
class CertificateManager
{
    public const STATUS_KEY = 'softswitch.certificate.status';

    public const TASK_KEY = 'softswitch.certificate.task';

    /** @return array{mode: string, domain: string, expires: string, subject: string}|null */
    public function cachedStatus(): ?array
    {
        return Cache::get(self::STATUS_KEY);
    }

    /** @return array{state: string, action: string, message: ?string, at: string}|null */
    public function task(): ?array
    {
        return Cache::get(self::TASK_KEY);
    }

    /**
     * True while a certificate action is queued or running. A task that has
     * shown no progress for 10 minutes (e.g. the worker died) no longer blocks.
     */
    public function busy(): bool
    {
        $task = $this->task();

        return $task !== null
            && in_array($task['state'], ['queued', 'running'], true)
            && now()->subMinutes(10)->lt($task['at']);
    }

    public function markTask(string $state, string $action, ?string $message = null): void
    {
        Cache::forever(self::TASK_KEY, [
            'state' => $state,
            'action' => $action,
            'message' => $message,
            'at' => now()->toIso8601String(),
        ]);
    }

    public function refreshStatus(): array
    {
        return $this->run(['status']);
    }

    public function issue(string $domain, string $email): array
    {
        return $this->run(['issue', $domain, $email]);
    }

    public function useSelfSigned(): array
    {
        return $this->run(['selfsigned']);
    }

    /**
     * Returns the IPv4 addresses a domain resolves to.
     *
     * @return list<string>
     */
    public function resolve(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_A) ?: [];

        return array_values(array_unique(array_column($records, 'ip')));
    }

    private function run(array $args): array
    {
        $result = Process::timeout(180)->run(['sudo', '-n', config('softswitch.certificate.helper'), ...$args]);

        $json = json_decode(trim($result->output()), true);
        if (! is_array($json)) {
            throw new RuntimeException('Certificate helper returned no result: '.trim($result->errorOutput() ?: $result->output()));
        }
        if (! ($json['ok'] ?? false)) {
            throw new RuntimeException($json['error'] ?? 'Certificate helper failed.');
        }

        $status = array_intersect_key($json, array_flip(['mode', 'domain', 'expires', 'subject']));
        // openssl prints e.g. "Nov  4 03:52:09 2036 GMT"; hand the UI ISO 8601.
        $expires = strtotime((string) ($status['expires'] ?? ''));
        $status['expires'] = $expires ? date(DATE_ATOM, $expires) : '';
        Cache::forever(self::STATUS_KEY, $status);

        return $status;
    }
}
