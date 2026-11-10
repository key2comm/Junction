<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Settings → Branding: the company's own logo (shown top left and on the
 * sign-in page), its icon (browser tab / bookmarks) and the company name for
 * the page footer ("© <year> <name>"). The files live on the private local
 * disk and are served by BrandingController at /branding/{logo,icon}, with a
 * version in the URL so browsers pick up a new upload straight away.
 */
final class Branding
{
    public const COMPANY = 'branding.company_name';

    /** Setting key holding {path, type, version} for each image. */
    public const FILES = ['logo' => 'branding.logo', 'icon' => 'branding.icon'];

    public const TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
    ];

    public static function companyName(): ?string
    {
        $name = trim((string) Settings::get(self::COMPANY, ''));

        return $name === '' ? null : $name;
    }

    /** @return array{path: string, type: string, version: int}|null */
    public static function file(string $kind): ?array
    {
        $file = Settings::get(self::FILES[$kind] ?? '', null);

        return is_array($file) && isset($file['path']) && Storage::disk('local')->exists($file['path']) ? $file : null;
    }

    public static function url(string $kind): ?string
    {
        $file = self::file($kind);

        return $file ? url("/branding/{$kind}").'?v='.$file['version'] : null;
    }

    public static function store(string $kind, UploadedFile $upload): void
    {
        $ext = strtolower($upload->getClientOriginalExtension());
        $old = self::file($kind);
        $path = $upload->storeAs('branding', "{$kind}-".time().".{$ext}", 'local');
        Settings::put([self::FILES[$kind] => ['path' => $path, 'type' => self::TYPES[$ext] ?? 'application/octet-stream', 'version' => time()]]);
        if ($old && $old['path'] !== $path) {
            Storage::disk('local')->delete($old['path']);
        }
    }

    public static function remove(string $kind): void
    {
        if ($old = self::file($kind)) {
            Storage::disk('local')->delete($old['path']);
        }
        Settings::put([self::FILES[$kind] => null]);
    }

    /** For every page (shared Inertia prop). */
    public static function shared(): array
    {
        return ['logo' => self::url('logo'), 'icon' => self::url('icon'), 'company' => self::companyName()];
    }
}
