<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\RunReport;
use App\Models\Account;
use App\Models\ReportFile;
use App\Models\ReportSchedule;
use App\Services\MailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings → Scheduled reports: call records (CDR) CSVs made every day, week
 * or month, kept on the server, optionally emailed and uploaded by SFTP.
 */
class ReportController extends Controller
{
    public const DIRECTIONS = ['incoming' => 'Incoming (DIDs)', 'outgoing' => 'Outgoing', 'internal' => 'PBX internal'];

    public const STATUSES = ['answered' => 'Answered', 'failed' => 'Failed', 'rejected' => 'Rejected'];

    public function index(): Response
    {
        $schedules = ReportSchedule::query()->withCount('files')->orderBy('name')->get();
        $customers = Account::query()->whereIn('id', $schedules->pluck('filters.customer')->filter())->pluck('code', 'id');
        $carriers = Account::query()->whereIn('id', $schedules->pluck('filters.vendor')->filter())->pluck('name', 'id');

        return Inertia::render('settings/Reports', [
            'schedules' => $schedules->map(fn (ReportSchedule $s) => [
                ...$s->only(['id', 'name', 'enabled', 'frequency', 'timezone', 'retention_days', 'sftp_enabled', 'sftp_host', 'last_run_at', 'last_error', 'files_count']),
                'emails' => $s->emailAddresses(),
                'filters' => array_values(array_filter([
                    ($id = $s->filters['customer'] ?? null) ? 'Customer '.($customers[$id] ?? "#{$id}") : null,
                    ($id = $s->filters['vendor'] ?? null) ? 'Carrier '.($carriers[$id] ?? "#{$id}") : null,
                    self::DIRECTIONS[$s->filters['direction'] ?? ''] ?? null,
                    self::STATUSES[$s->filters['status'] ?? ''] ?? null,
                ])),
            ]),
            'files' => ReportFile::query()->with('schedule:id,name,timezone')->latest('id')->limit(200)->get()->map(fn (ReportFile $f) => [
                ...$f->only(['id', 'filename', 'size', 'rows', 'period_start', 'period_end', 'emailed_at', 'uploaded_at', 'error', 'created_at']),
                'schedule' => $f->schedule->only(['id', 'name', 'timezone']),
            ]),
            'frequencies' => ReportSchedule::FREQUENCIES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('settings/ReportForm', ['schedule' => null, ...$this->options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $schedule = ReportSchedule::create($this->validated($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Schedule {$schedule->name} created. Its first report is made after the current period ends; use Run now to make one for the last period."]);

        return to_route('reports.index');
    }

    public function edit(ReportSchedule $report): Response
    {
        return Inertia::render('settings/ReportForm', [
            'schedule' => [
                ...$report->only(['id', 'name', 'enabled', 'frequency', 'timezone', 'filters', 'retention_days', 'email_to',
                    'sftp_enabled', 'sftp_host', 'sftp_port', 'sftp_username', 'sftp_path', 'sftp_fingerprint']),
                'has_password' => $report->getRawOriginal('sftp_password') !== null,
                'has_key' => $report->getRawOriginal('sftp_private_key') !== null,
            ],
            ...$this->options(),
        ]);
    }

    public function update(Request $request, ReportSchedule $report): RedirectResponse
    {
        $report->update($this->validated($request, $report));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Schedule {$report->name} saved."]);

        return to_route('reports.index');
    }

    public function destroy(ReportSchedule $report): RedirectResponse
    {
        $report->files()->get()->each->delete(); // their CSVs too
        $report->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => "Schedule {$report->name} and its reports deleted."]);

        return to_route('reports.index');
    }

    public function run(ReportSchedule $report): RedirectResponse
    {
        RunReport::dispatch($report->id);
        [$start, $end] = $report->lastPeriod();
        $tz = $report->timezone ?: 'UTC';
        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf('Making the report for %s to %s (%s). It appears below in a moment.',
            $start->setTimezone($tz)->format('j M Y'), $end->setTimezone($tz)->subSecond()->format('j M Y'), $tz)]);

        return to_route('reports.index');
    }

    public function download(ReportFile $file): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->download($file->path, $file->filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function destroyFile(ReportFile $file): RedirectResponse
    {
        $file->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$file->filename} deleted."]);

        return to_route('reports.index');
    }

    private function options(): array
    {
        return [
            'frequencies' => ReportSchedule::FREQUENCIES,
            'timezones' => timezone_identifiers_list(),
            'customers' => Account::query()->where('is_customer', true)->orderBy('name')->get(['id', 'name', 'code']),
            'carriers' => Account::query()->where('is_vendor', true)->orderBy('name')->get(['id', 'name', 'code']),
            'directions' => self::DIRECTIONS,
            'statuses' => self::STATUSES,
            'mailConfigured' => MailSettings::configured(),
        ];
    }

    private function validated(Request $request, ?ReportSchedule $schedule = null): array
    {
        $sftp = $request->boolean('sftp_enabled');
        $request->merge([
            'enabled' => $request->boolean('enabled'),
            'sftp_enabled' => $sftp,
            'sftp_host' => trim((string) $request->input('sftp_host')) ?: null,
            'sftp_fingerprint' => trim((string) $request->input('sftp_fingerprint')) ?: null,
        ]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'enabled' => ['boolean'],
            'frequency' => ['required', Rule::in(array_keys(ReportSchedule::FREQUENCIES))],
            'timezone' => ['required', 'timezone:all'],
            'retention_days' => ['required', 'integer', 'between:1,3650'],
            'filters' => ['array'],
            'filters.customer' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('is_customer', true)],
            'filters.vendor' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('is_vendor', true)],
            'filters.direction' => ['nullable', Rule::in(array_keys(self::DIRECTIONS))],
            'filters.status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'email_to' => ['nullable', 'string', 'max:1000'],
            'sftp_enabled' => ['boolean'],
            'sftp_host' => [$sftp ? 'required' : 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/'],
            'sftp_port' => [$sftp ? 'required' : 'nullable', 'integer', 'between:1,65535'],
            'sftp_username' => [$sftp ? 'required' : 'nullable', 'string', 'max:128'],
            'sftp_password' => ['nullable', 'string', 'max:255'],
            'sftp_private_key' => ['nullable', 'string', 'max:20000'],
            'sftp_path' => ['nullable', 'string', 'max:255', 'not_regex:/\.\./'],
            'sftp_fingerprint' => ['nullable', 'string', 'max:128'],
        ], [
            'sftp_host.regex' => 'Enter a host name or IP address.',
            'sftp_path.not_regex' => 'Use a plain directory path (no "..").',
        ]);

        $emails = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) ($data['email_to'] ?? '')) ?: [])));
        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['email_to' => "{$email} is not an email address."]);
            }
        }

        $out = [
            ...collect($data)->only(['name', 'enabled', 'frequency', 'timezone', 'retention_days', 'sftp_enabled', 'sftp_host', 'sftp_username', 'sftp_path', 'sftp_fingerprint'])->all(),
            'sftp_port' => $data['sftp_port'] ?? 22,
            'filters' => array_filter($data['filters'] ?? [], fn ($v) => $v !== null && $v !== ''),
            'email_to' => $emails === [] ? null : implode(', ', $emails),
        ];
        // Secrets are write-only: blank keeps the saved one, the "remove" boxes clear it.
        foreach (['sftp_password' => 'clear_password', 'sftp_private_key' => 'clear_key'] as $field => $clear) {
            if (($data[$field] ?? '') !== '') {
                $out[$field] = $data[$field];
            } elseif ($request->boolean($clear)) {
                $out[$field] = null;
            }
        }
        if ($sftp) {
            $hasPassword = array_key_exists('sftp_password', $out) ? $out['sftp_password'] !== null : $schedule?->getRawOriginal('sftp_password') !== null;
            $hasKey = array_key_exists('sftp_private_key', $out) ? $out['sftp_private_key'] !== null : $schedule?->getRawOriginal('sftp_private_key') !== null;
            if (! $hasPassword && ! $hasKey) {
                throw ValidationException::withMessages(['sftp_password' => 'Enter the SFTP password or a private key.']);
            }
        }

        return $out;
    }
}
