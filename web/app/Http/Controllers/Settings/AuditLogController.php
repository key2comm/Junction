<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Settings → Audit log (owners and admins): who changed what, and sign-ins. */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'user' => ['nullable', 'integer'],
            'event' => ['nullable', Rule::in(array_keys(AuditLog::EVENTS))],
            'via' => ['nullable', Rule::in(array_keys(AuditLog::VIA))],
            'type' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $like = '%'.addcslashes(trim((string) ($f['q'] ?? '')), '%_\\').'%';

        $logs = AuditLog::query()->with('account:id,name,code')
            ->when(trim((string) ($f['q'] ?? '')) !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('subject_label', 'ilike', $like)->orWhere('user_name', 'ilike', $like)->orWhere('user_email', 'ilike', $like)
                ->orWhere('ip', 'ilike', $like)->orWhere('path', 'ilike', $like)->orWhere('action', 'ilike', $like)
                ->orWhereRaw('changes::text ilike ?', [$like])->orWhereRaw('input::text ilike ?', [$like])))
            ->when($f['user'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($f['event'] ?? null, fn ($q, $e) => $q->where('event', $e))
            ->when($f['via'] ?? null, fn ($q, $v) => $q->where('via', $v))
            ->when($f['type'] ?? null, fn ($q, $t) => $q->where('subject_type', $t))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', CarbonImmutable::parse($d)->utc()))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<', CarbonImmutable::parse($d)->utc()))
            ->latest('id')->paginate(50)->withQueryString()
            ->through(fn (AuditLog $l) => [
                ...$l->only(['id', 'created_at', 'user_id', 'user_name', 'user_email', 'via', 'event', 'action', 'method', 'path', 'ip',
                    'subject_type', 'subject_id', 'subject_label', 'changes', 'input']),
                'account' => $l->account?->only(['id', 'name', 'code']),
                'summary' => $l->summary(),
            ]);

        return Inertia::render('settings/AuditLog', [
            'logs' => $logs,
            'filters' => ['q' => $f['q'] ?? '', 'user' => $f['user'] ?? null, 'event' => $f['event'] ?? '', 'via' => $f['via'] ?? '',
                'type' => $f['type'] ?? '', 'from' => $f['from'] ?? '', 'to' => $f['to'] ?? ''],
            'users' => User::query()->whereNull('account_id')->orderBy('name')->get(['id', 'name', 'email']),
            'types' => AuditLog::query()->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type'),
            'events' => AuditLog::EVENTS,
            'via' => AuditLog::VIA,
            'retention' => Audit::retentionDays(),
            'retentionChoices' => Audit::RETENTION_CHOICES,
        ]);
    }

    /** Owners only (see AdminRole): how long entries are kept. */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['retention_days' => ['required', 'integer', Rule::in(Audit::RETENTION_CHOICES)]]);
        Settings::put([Audit::RETENTION => (int) $data['retention_days']]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Audit log retention saved.']);

        return to_route('audit.index');
    }
}
