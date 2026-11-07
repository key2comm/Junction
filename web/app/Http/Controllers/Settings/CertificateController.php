<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\ManageCertificate;
use App\Services\CertificateManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CertificateController extends Controller
{
    public function edit(Request $request, CertificateManager $certificates): Response
    {
        $status = $certificates->cachedStatus();
        if ($status === null && ! $certificates->busy()) {
            ManageCertificate::dispatch('status');
        }

        return Inertia::render('settings/Certificate', [
            'status' => $status,
            'task' => $certificates->task(),
            'busy' => $certificates->busy(),
            'defaults' => [
                'domain' => ($status['domain'] ?? '') ?: parse_url((string) config('app.url'), PHP_URL_HOST),
                'email' => $request->user()->email,
            ],
            'publicIp' => config('softswitch.public_ip'),
        ]);
    }

    /**
     * Request (or renew) a Let's Encrypt certificate for a domain.
     */
    public function store(Request $request, CertificateManager $certificates): RedirectResponse
    {
        if ($certificates->busy()) {
            throw ValidationException::withMessages(['domain' => 'A certificate change is already in progress.']);
        }

        $request->merge(['domain' => strtolower(trim((string) $request->input('domain')))]);

        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253', 'regex:/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/'],
            'email' => ['required', 'email', 'max:254'],
        ], [
            'domain.regex' => 'Enter a fully qualified domain name, e.g. switch.example.com.',
        ]);
        $domain = $data['domain'];

        // Let's Encrypt validates over HTTP to this server, so DNS must point here.
        $publicIp = config('softswitch.public_ip');
        $resolved = $certificates->resolve($domain);
        if ($resolved === []) {
            throw ValidationException::withMessages(['domain' => "{$domain} does not resolve. Create an A record pointing to {$publicIp} first."]);
        }
        if ($publicIp && ! in_array($publicIp, $resolved, true)) {
            throw ValidationException::withMessages(['domain' => "{$domain} points to ".implode(', ', $resolved).", not this server ({$publicIp})."]);
        }

        $certificates->markTask('queued', 'issue');
        ManageCertificate::dispatch('issue', $domain, $data['email']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Certificate request started.')]);

        return to_route('certificate.edit');
    }

    /**
     * Switch back to the self-signed certificate.
     */
    public function destroy(CertificateManager $certificates): RedirectResponse
    {
        if ($certificates->busy()) {
            throw ValidationException::withMessages(['domain' => 'A certificate change is already in progress.']);
        }

        $certificates->markTask('queued', 'selfsigned');
        ManageCertificate::dispatch('selfsigned');

        return to_route('certificate.edit');
    }
}
