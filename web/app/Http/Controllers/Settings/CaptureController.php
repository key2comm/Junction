<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\Captures;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/** Settings → Call captures: per-call SIP PCAPs on/off and how long they're kept. */
class CaptureController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/Captures', [
            'settings' => ['enabled' => Captures::enabled(), 'retention_days' => Captures::retentionDays()],
            'status' => Cache::get('captures.status'),
            'rawHours' => Captures::RAW_HOURS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['boolean'],
            'retention_days' => ['required', 'integer', 'between:1,3650'],
        ]);
        Settings::put([Captures::ENABLED => $request->boolean('enabled'), Captures::RETENTION => (int) $data['retention_days']]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Call capture settings saved.']);

        return to_route('captures.edit');
    }
}
