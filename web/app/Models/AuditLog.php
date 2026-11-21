<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Settings → Audit log: one change request, sign-in or sign-out (see App\Support\Audit). Never edited. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public const EVENTS = ['change' => 'Changes', 'login' => 'Sign-ins', 'logout' => 'Sign-outs', 'login_failed' => 'Failed sign-ins'];

    public const VIA = ['web' => 'Admin', 'portal' => 'Customer portal', 'api' => 'REST API'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'input' => 'array', 'created_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** "Changed DID 14165550100", "Signed in", "Run (backups)"... */
    public function summary(): string
    {
        return match ($this->event) {
            'login' => 'Signed in',
            'logout' => 'Signed out',
            'login_failed' => 'Failed sign-in'.($this->user_email ? " as {$this->user_email}" : ''),
            default => $this->changeSummary(),
        };
    }

    private function changeSummary(): string
    {
        $changes = $this->getAttribute('changes') ?? [];
        if ($changes !== []) {
            $first = $changes[0];
            $verb = ['created' => 'Added', 'updated' => 'Changed', 'deleted' => 'Deleted'][$first['event']] ?? 'Changed';
            $text = trim("{$verb} {$first['type']} ".($first['label'] ?? (isset($first['id']) ? "#{$first['id']}" : '')));
            $more = count($changes) - 1;

            return $more > 0 ? "{$text} (and {$more} more)" : $text;
        }
        $parts = explode('.', (string) $this->action);
        $verb = array_pop($parts);
        $verb = ['store' => 'Add', 'update' => 'Change', 'destroy' => 'Delete'][$verb] ?? Str::headline((string) $verb);

        return $parts === [] ? ($verb ?: $this->method.' '.$this->path) : $verb.' · '.Str::headline(implode(' ', $parts));
    }
}
