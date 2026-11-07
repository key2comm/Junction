<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Services\AlertEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AlertController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->validate(['status' => ['nullable', 'in:live,resolved,all']])['status'] ?? 'live';

        return Inertia::render('alerts/Index', [
            'alerts' => Alert::query()->with(['account:id,code,name,status', 'trunk:id,name', 'gateway:id,name'])
                ->when($status === 'live', fn ($q) => $q->whereIn('status', ['open', 'acknowledged']))
                ->when($status === 'resolved', fn ($q) => $q->where('status', 'resolved'))
                ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'acknowledged' THEN 1 ELSE 2 END")
                ->orderByDesc('opened_at')->limit(200)->get()
                ->map(fn (Alert $a) => [
                    ...$a->only(['id', 'type', 'severity', 'status', 'title', 'details', 'action_taken', 'opened_at', 'last_seen_at', 'resolved_at', 'acknowledged_at', 'emailed_at']),
                    'label' => AlertEngine::RULES[$a->type]['label'] ?? $a->type,
                    'kind' => AlertEngine::RULES[$a->type]['kind'] ?? 'operational',
                    'account' => $a->account?->only(['id', 'code', 'name', 'status']),
                    'trunk' => $a->trunk?->only(['id', 'name']),
                    'gateway' => $a->gateway?->name,
                ]),
            'status' => $status,
            'rules' => collect(AlertEngine::RULES)->map(function ($meta, $type) {
                $row = DB::table('alert_rules')->where('type', $type)->first();

                return [...$meta, 'type' => $type, 'enabled' => (bool) ($row->enabled ?? false),
                    'auto_suspend' => (bool) ($row->auto_suspend ?? false), 'params' => json_decode($row->params ?? '{}', true)];
            })->values(),
        ]);
    }

    public function updateRule(Request $request, string $type): RedirectResponse
    {
        abort_unless(isset(AlertEngine::RULES[$type]), 404);
        $current = json_decode((string) DB::table('alert_rules')->where('type', $type)->value('params'), true) ?: [];
        $data = $request->validate([
            'enabled' => ['boolean'],
            'auto_suspend' => ['boolean'],
            'params' => ['array'],
            ...collect($current)->mapWithKeys(fn ($v, $k) => ["params.{$k}" => ['required', 'numeric', 'min:0', 'max:100000000']])->all(),
        ]);

        DB::table('alert_rules')->where('type', $type)->update([
            'enabled' => $request->boolean('enabled'),
            // Only fraud rules may suspend customers.
            'auto_suspend' => AlertEngine::RULES[$type]['kind'] === 'fraud' && $request->boolean('auto_suspend'),
            'params' => json_encode(array_map(fn ($v) => $v + 0, array_intersect_key($data['params'] ?? [], $current) + $current)),
            'updated_at' => now(),
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => (AlertEngine::RULES[$type]['label']).' rule saved.']);

        return to_route('alerts.index');
    }

    public function acknowledge(Request $request, Alert $alert): RedirectResponse
    {
        if ($alert->status === 'open') {
            $alert->update(['status' => 'acknowledged', 'acknowledged_by' => $request->user()->id, 'acknowledged_at' => now()]);
        }

        return back();
    }

    public function resolve(Request $request, Alert $alert): RedirectResponse
    {
        if ($alert->status !== 'resolved') {
            $alert->update(['status' => 'resolved', 'resolved_at' => now(), 'acknowledged_by' => $alert->acknowledged_by ?? $request->user()->id]);
        }

        return back();
    }

    /** API: GET /api/v1/alerts */
    public function api(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', 'in:live,resolved,all']])['status'] ?? 'live';

        return response()->json(['data' => Alert::query()
            ->when($status === 'live', fn ($q) => $q->whereIn('status', ['open', 'acknowledged']))
            ->when($status === 'resolved', fn ($q) => $q->where('status', 'resolved'))
            ->orderByDesc('opened_at')->limit(500)->get()
            ->map(fn (Alert $a) => [
                ...$a->only(['id', 'type', 'severity', 'status', 'title', 'details', 'action_taken', 'account_id', 'trunk_id', 'gateway_id']),
                'opened_at' => $a->opened_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                'last_seen_at' => $a->last_seen_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                'resolved_at' => $a->resolved_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            ])]);
    }
}
