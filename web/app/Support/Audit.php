<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Cdr;
use App\Models\CdrAttempt;
use App\Models\ReportFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Settings → Audit log: who changed what. RecordAudit starts collecting for
 * each change request (POST, PUT, PATCH, DELETE) by a signed-in user; model
 * events and Settings::put add what changed; the request then writes one
 * audit_logs row. Sign-ins, sign-outs and failed sign-ins are written too.
 * Secrets (passwords, keys, encrypted fields) are never stored: only that
 * they changed. Work done by the scheduler or queue is not logged.
 */
final class Audit
{
    public const RETENTION = 'audit.retention_days';

    public const DEFAULT_RETENTION = 365;

    public const RETENTION_CHOICES = [90, 180, 365, 730, 1825, 3650];

    /** Most changed records kept per request (a bulk change lists the first ones). */
    public const MAX_CHANGES = 100;

    /** Call records and other machine-made rows are not audited. */
    private const IGNORED_MODELS = [AuditLog::class, Cdr::class, CdrAttempt::class, Backup::class, ReportFile::class];

    private const IGNORED_FIELDS = ['created_at', 'updated_at', 'remember_token', 'last_used_at', 'last_used_ip', 'last_run_at'];

    /** Values of fields with these names are hidden. */
    private const SECRET_FIELD = '/pass|secret|token|private_key|_pin$|^pin$|two_factor|api_key/i';

    /** Requests not logged here (sign-in is logged by its own event). */
    private const SKIP_ROUTES = ['login', 'login.*', 'logout', 'password.confirm*', 'password.email', 'password.store', 'two-factor.login*', 'passkey*', 'webauthn*'];

    private const TYPES = [
        'PbxDid' => 'DID', 'Endpoint' => 'PBX/Device', 'EndpointRule' => 'PBX/Device rule', 'Gateway' => 'SIP trunk', 'RoutingPlan' => 'LCR group',
        'RoutingPlanRoute' => 'LCR group carrier', 'RateDeck' => 'Rate deck', 'Trunk' => 'Carrier routing', 'AccountIp' => 'Account IP',
        'BlockedNumber' => 'Blocked number', 'BlockedPrefix' => 'Blocked prefix', 'PbxExtension' => 'Extension', 'PbxIvr' => 'IVR',
        'ApiToken' => 'API token', 'LedgerEntry' => 'Ledger entry', 'VendorLedgerEntry' => 'Carrier ledger entry', 'AccountCharge' => 'Billing line',
        'FirewallEntry' => 'Firewall entry', 'ReportSchedule' => 'Scheduled report', 'AdminInvitation' => 'Admin invitation',
    ];

    /** @var list<array<string, mixed>>|null changes collected for the current request; null = not collecting */
    private static ?array $changes = null;

    public static function begin(): void
    {
        self::$changes = [];
    }

    public static function active(): bool
    {
        return self::$changes !== null;
    }

    /** @return list<array<string, mixed>> */
    public static function end(): array
    {
        $changes = self::$changes ?? [];
        self::$changes = null;

        return $changes;
    }

    public static function skips(Request $request): bool
    {
        return Str::is(self::SKIP_ROUTES, (string) $request->route()?->getName());
    }

    /** A model was created, updated or deleted (eloquent events). */
    public static function model(string $event, Model $model): void
    {
        if (self::$changes === null || in_array($model::class, self::IGNORED_MODELS, true) || count(self::$changes) >= self::MAX_CHANGES) {
            return;
        }
        $fields = [];
        if ($event === 'updated') {
            foreach (array_keys($model->getChanges()) as $f) {
                if (! in_array($f, self::IGNORED_FIELDS, true)) {
                    $fields[$f] = [self::value($model, $f, $model->getRawOriginal($f)), self::value($model, $f, $model->getAttributes()[$f] ?? null)];
                }
            }
            if ($fields === []) {
                return;
            }
        } else {
            foreach ($model->getAttributes() as $f => $v) {
                if ($v !== null && $v !== '' && ! in_array($f, self::IGNORED_FIELDS, true)) {
                    $fields[$f] = $event === 'created' ? [null, self::value($model, $f, $v)] : [self::value($model, $f, $v), null];
                }
            }
        }
        self::$changes[] = [
            'event' => $event,
            'type' => self::type($model),
            'id' => $model->getKey(),
            'label' => self::label($model),
            'fields' => $fields,
        ];
    }

    /** Settings::put: settings stored in app_settings. */
    public static function settings(array $before, array $after): void
    {
        if (self::$changes === null) {
            return;
        }
        $fields = [];
        foreach ($after as $key => $new) {
            $old = $before[$key] ?? null;
            if ($old === $new || ($old === null && $new === '')) {
                continue;
            }
            $secret = in_array($key, Settings::SECRET, true) || preg_match(self::SECRET_FIELD, $key);
            $fields[$key] = $secret ? [$old === null ? null : '••••', $new === null || $new === '' ? null : '••••'] : [self::trim($old), self::trim($new)];
        }
        if ($fields !== []) {
            self::$changes[] = ['event' => 'updated', 'type' => 'Setting', 'id' => null, 'label' => implode(', ', array_keys($fields)), 'fields' => $fields];
        }
    }

    /** RecordAudit: write the request's row (nothing for failed requests). */
    public static function request(Request $request, Response $response): void
    {
        if (self::$changes === null) {
            return; // stopped: the request was refused (access level)
        }
        $changes = self::end();
        $user = $request->user();
        if (! $user instanceof User || $response->getStatusCode() >= 500) {
            return;
        }
        $refused = $response->getStatusCode() >= 400 || ($request->hasSession() && $request->session()->get('errors')?->any());
        if ($changes === [] && $refused) {
            return;
        }
        $first = $changes[0] ?? null;
        self::write([
            ...self::who($request, $user),
            'event' => 'change',
            'action' => $request->route()?->getName(),
            'method' => $request->method(),
            'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 500),
            'subject_type' => $first['type'] ?? null,
            'subject_id' => is_numeric($first['id'] ?? null) ? (int) $first['id'] : null,
            'subject_label' => isset($first['label']) ? mb_substr((string) $first['label'], 0, 255) : null,
            'changes' => $changes === [] ? null : $changes,
            // What was sent, when no record shows the change (imports, actions such as Run now or Hang up).
            'input' => $changes === [] ? (self::input($request) ?: null) : null,
        ]);
    }

    /** Sign-in, sign-out and failed sign-in. */
    public static function auth(string $event, ?User $user, Request $request, ?string $email = null): void
    {
        self::write([
            ...($user ? self::who($request, $user) : ['user_id' => null, 'user_name' => null, 'user_email' => $email ? mb_substr($email, 0, 255) : null,
                'account_id' => null, 'via' => 'web', 'ip' => $request->ip()]),
            'event' => $event,
            'action' => $request->route()?->getName(),
            'method' => $request->method(),
            'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 500),
        ]);
    }

    public static function retentionDays(): int
    {
        return max(30, (int) Settings::get(self::RETENTION, self::DEFAULT_RETENTION));
    }

    public static function prune(): int
    {
        return AuditLog::query()->where('created_at', '<', now()->subDays(self::retentionDays()))->delete();
    }

    /** The log must never break what it records (e.g. a backup server's read-only database). */
    private static function write(array $row): void
    {
        try {
            AuditLog::query()->create($row);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function who(Request $request, User $user): array
    {
        return [
            'user_id' => $user->id,
            'user_name' => mb_substr((string) $user->name, 0, 255),
            'user_email' => mb_substr((string) $user->email, 0, 255),
            'account_id' => $user->account_id,
            'via' => $request->is('api/*') ? 'api' : ($user->account_id ? 'portal' : 'web'),
            'ip' => $request->ip(),
        ];
    }

    private static function input(Request $request): array
    {
        $clean = function (mixed $value, int|string $key = '') use (&$clean): mixed {
            if (is_string($key) && $key !== '' && preg_match(self::SECRET_FIELD, $key)) {
                return '••••';
            }
            if (is_array($value)) {
                $out = [];
                foreach ($value as $k => $v) {
                    $out[$k] = $clean($v, $k);
                }

                return $out;
            }

            return is_object($value) ? null : self::trim($value);
        };
        $out = $clean($request->except(['_token', '_method']));
        foreach ($request->allFiles() as $key => $file) {
            $out[$key] = is_array($file) ? '(files)' : '(file '.$file->getClientOriginalName().')';
        }
        $json = json_encode($out);

        return $json !== false && strlen($json) > 4000 ? ['(too long to keep)' => array_keys($out)] : $out;
    }

    private static function value(Model $model, string $field, mixed $value): mixed
    {
        $cast = (string) ($model->getCasts()[$field] ?? '');
        if (in_array($field, $model->getHidden(), true) || str_starts_with($cast, 'encrypted') || $cast === 'hashed' || preg_match(self::SECRET_FIELD, $field)) {
            return $value === null || $value === '' ? null : '••••';
        }
        if (is_string($value) && $value !== '' && ($value[0] === '{' || $value[0] === '[') && is_array($json = json_decode($value, true))) {
            return $json;
        }

        return self::trim($value);
    }

    private static function trim(mixed $value): mixed
    {
        return is_string($value) && mb_strlen($value) > 300 ? mb_substr($value, 0, 300).'…' : $value;
    }

    private static function type(Model $model): string
    {
        $base = class_basename($model);

        return self::TYPES[$base] ?? Str::headline($base);
    }

    private static function label(Model $model): ?string
    {
        $a = $model->getAttributes();
        foreach (['name', 'label', 'number', 'code', 'extension', 'prefix', 'email', 'filename', 'title', 'value', 'description'] as $f) {
            if (isset($a[$f]) && is_scalar($a[$f]) && $a[$f] !== '') {
                return mb_substr((string) $a[$f], 0, 255);
            }
        }

        return null;
    }
}
