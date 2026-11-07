<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\HangupCall;
use App\Services\LiveCalls;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LiveCallsController extends Controller
{
    public function index(): Response
    {
        LiveCalls::watch();

        return Inertia::render('live/Index', ['snapshot' => LiveCalls::snapshot()]);
    }

    /** API: GET /api/v1/live-calls */
    public function api(): JsonResponse
    {
        LiveCalls::watch();
        $snapshot = LiveCalls::snapshot();

        return response()->json(['data' => $snapshot['calls'], 'meta' => ['snapshot_at' => $snapshot['at']]]);
    }

    public function hangup(Request $request, string $uuid): RedirectResponse
    {
        // Only customer calls currently in the snapshot can be hung up from here.
        $known = collect(LiveCalls::snapshot()['calls'])->contains('uuid', $uuid);
        if (! preg_match('/^[0-9a-f-]{36}$/i', $uuid) || ! $known) {
            throw ValidationException::withMessages(['uuid' => 'That call is no longer in progress.']);
        }
        HangupCall::dispatch($uuid, $request->user()?->id);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Hanging up the call…']);

        return to_route('live.index');
    }
}
