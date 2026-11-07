<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\SendTestEmail;
use App\Services\MailSettings;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Email: SMTP server used for invoices and alerts. The password is
 * write-only (stored encrypted, never sent back to the browser).
 */
class EmailController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Email', [
            'mail' => [
                'host' => Settings::get('mail.host'),
                'port' => Settings::get('mail.port'),
                'security' => Settings::get('mail.security', 'starttls'),
                'username' => Settings::get('mail.username'),
                'has_password' => Settings::get('mail.password') !== null,
                'from_address' => Settings::get('mail.from_address'),
                'from_name' => Settings::get('mail.from_name', config('app.name')),
                'admin_address' => Settings::get('mail.admin_address'),
            ],
            'configured' => MailSettings::configured(),
            'test' => Cache::get(SendTestEmail::RESULT),
            'defaultTestTo' => $request->user()->email,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'security' => ['required', 'in:starttls,tls'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'clear_password' => ['nullable', 'boolean'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'admin_address' => ['nullable', 'email', 'max:255'],
        ], ['host.regex' => 'Enter a hostname such as smtp.office365.com.']);

        $values = [
            'mail.host' => strtolower($data['host']),
            'mail.port' => (int) $data['port'],
            'mail.security' => $data['security'],
            'mail.username' => $data['username'] ?? null,
            'mail.from_address' => $data['from_address'],
            'mail.from_name' => $data['from_name'] ?? null,
            'mail.admin_address' => $data['admin_address'] ?? null,
        ];
        // Blank password keeps the saved one; tick "clear" to remove it.
        if ($request->boolean('clear_password')) {
            $values['mail.password'] = null;
        } elseif (($data['password'] ?? '') !== '') {
            $values['mail.password'] = $data['password'];
        }
        Settings::put($values);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Email settings saved. Send a test email to check them.']);

        return to_route('email.edit');
    }

    public function test(Request $request): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'email', 'max:255']]);
        Cache::put(SendTestEmail::RESULT, ['state' => 'queued', 'to' => $data['to'], 'message' => null, 'at' => now()->toIso8601String()], now()->addDay());
        SendTestEmail::dispatch($data['to']);

        return to_route('email.edit');
    }
}
