<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Services\HighAvailability;
use App\Services\SystemHealth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Settings → System health: the server's checks, figures, database size and Grafana setup. */
class SystemHealthController extends Controller
{
    public const DASHBOARD = 'grafana/junction-dashboard.json';

    public function index(): Response
    {
        ['fast' => $fast, 'slow' => $slow] = SystemHealth::readings();

        return Inertia::render('settings/SystemHealth', [
            'checks' => SystemHealth::checks(),
            'fast' => $fast,
            'slow' => $slow ? array_diff_key($slow, ['ha' => true]) : null,
            'standby' => HighAvailability::isStandby(),
            'metricsUrl' => url('/api/v1/metrics'),
            'metricsTokens' => ApiToken::query()->whereNull('revoked_at')->whereNull('account_id')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->whereJsonContains('abilities', 'metrics:read')->count(),
        ]);
    }

    /** The ready-made Grafana dashboard (import it in Grafana: Dashboards → New → Import). */
    public function grafana(): BinaryFileResponse
    {
        return response()->download(resource_path(self::DASHBOARD), 'junction-grafana-dashboard.json', ['Content-Type' => 'application/json']);
    }
}
