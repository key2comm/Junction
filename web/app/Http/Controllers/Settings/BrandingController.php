<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Branding;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Settings → Branding: company logo, icon and the footer's company name.
 * The images are served publicly (the sign-in page shows them too).
 */
class BrandingController extends Controller
{
    private const IMAGE_TYPES = 'mimetypes:image/png,image/jpeg,image/webp,image/svg+xml,image/x-icon,image/vnd.microsoft.icon,text/plain,text/xml,application/xml';

    public function edit(): Response
    {
        return Inertia::render('settings/Branding', [
            'settings' => Branding::shared(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'company_name' => ['nullable', 'string', 'max:100'],
            // SVGs without an XML declaration are detected as text, so the extension decides the type.
            'logo' => ['nullable', 'file', 'max:1024', 'extensions:png,jpg,jpeg,webp,svg', self::IMAGE_TYPES],
            'icon' => ['nullable', 'file', 'max:512', 'extensions:png,ico,svg', self::IMAGE_TYPES],
            'remove_logo' => ['boolean'],
            'remove_icon' => ['boolean'],
        ], [
            'logo.extensions' => 'Upload a PNG, JPG, WebP or SVG image.',
            'logo.mimetypes' => 'Upload a PNG, JPG, WebP or SVG image.',
            'logo.max' => 'The logo can be at most 1 MB.',
            'icon.extensions' => 'Upload a PNG, ICO or SVG image.',
            'icon.mimetypes' => 'Upload a PNG, ICO or SVG image.',
            'icon.max' => 'The icon can be at most 512 KB.',
        ]);

        Settings::put([Branding::COMPANY => trim((string) $request->input('company_name')) ?: null]);
        foreach (['logo', 'icon'] as $kind) {
            if ($request->hasFile($kind)) {
                Branding::store($kind, $request->file($kind));
            } elseif ($request->boolean("remove_{$kind}")) {
                Branding::remove($kind);
            }
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Branding saved.']);

        return to_route('branding.edit');
    }

    /** GET /branding/{logo|icon}: public, cached for a year (the URL carries a version). */
    public function show(string $kind): HttpResponse
    {
        $file = Branding::file($kind);
        abort_unless($file, 404);

        return response(Storage::disk('local')->get($file['path']), 200, [
            'Content-Type' => $file['type'],
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            // SVGs can carry scripts: never run anything from these files.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:",
        ]);
    }
}
