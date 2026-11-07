<?php

namespace App\Services;

use App\Support\Settings;

/**
 * Applies the SMTP settings from Settings → Email to Laravel's mail config.
 * Runs when the mailer is first resolved (i.e. only when mail is sent).
 */
class MailSettings
{
    public static function configured(): bool
    {
        return Settings::get('mail.host') !== null && Settings::get('mail.from_address') !== null;
    }

    public static function apply(): void
    {
        if (! self::configured()) {
            return;
        }
        $security = Settings::get('mail.security', 'starttls');

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            // smtps = implicit TLS (465); smtp upgrades with STARTTLS when offered (587).
            'mail.mailers.smtp.scheme' => $security === 'tls' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.host' => Settings::get('mail.host'),
            'mail.mailers.smtp.port' => (int) Settings::get('mail.port', $security === 'tls' ? 465 : 587),
            'mail.mailers.smtp.username' => Settings::get('mail.username'),
            'mail.mailers.smtp.password' => Settings::get('mail.password'),
            'mail.mailers.smtp.timeout' => 20,
            'mail.from.address' => Settings::get('mail.from_address'),
            'mail.from.name' => Settings::get('mail.from_name', config('app.name')),
        ]);
    }

    /**
     * Use the current settings for the next message. The queue worker is
     * long-running, so a mailer built before the settings changed is dropped.
     */
    public static function fresh(): bool
    {
        self::apply();
        app('mail.manager')->purge('smtp');

        return self::configured();
    }

    /** Where your own copies of alerts go (falls back to the From address). */
    public static function adminAddress(): ?string
    {
        return Settings::get('mail.admin_address') ?? Settings::get('mail.from_address');
    }
}
