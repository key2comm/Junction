<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Filters for the call records list, its summary and CSV export (shared so the
 * three always agree). Time range defaults to the last 24 hours, max 31 days.
 */
final class CdrFilters
{
    public const MAX_DAYS = 31;

    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly array $values,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'customer' => ['nullable', 'integer'],
            'vendor' => ['nullable', 'integer'],
            'trunk' => ['nullable', 'integer'],
            'number' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+\s\-]*$/'],
            'caller' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+\s\-]*$/'],
            'status' => ['nullable', 'in:answered,failed,rejected'],
            'direction' => ['nullable', 'in:incoming,outgoing,internal'],
            'sip' => ['nullable', 'integer', 'between:100,699'],
            'reason' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z_]+$/'],
        ]);

        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'])->utc() : CarbonImmutable::now('UTC');
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'])->utc() : $to->subDay();
        if ($from->greaterThanOrEqualTo($to)) {
            throw ValidationException::withMessages(['from' => 'The start must be before the end.']);
        }
        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => 'Choose a range of '.self::MAX_DAYS.' days or less.']);
        }

        $digits = fn ($v) => $v === null ? null : (preg_replace('/\D/', '', $v) ?: null);
        $values = array_filter([
            'customer' => $data['customer'] ?? null,
            'vendor' => $data['vendor'] ?? null,
            'trunk' => $data['trunk'] ?? null,
            'number' => $digits($data['number'] ?? null),
            'caller' => $digits($data['caller'] ?? null),
            'status' => $data['status'] ?? null,
            'direction' => $data['direction'] ?? null,
            'sip' => $data['sip'] ?? null,
            'reason' => $data['reason'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        return new self($from, $to, $values);
    }

    public function apply(Builder $query): Builder
    {
        $v = $this->values;

        return $query
            ->where('started_at', '>=', $this->from)
            ->where('started_at', '<', $this->to)
            ->when($v['customer'] ?? null, fn ($q, $id) => $q->where('customer_account_id', $id))
            ->when($v['vendor'] ?? null, fn ($q, $id) => $q->where('vendor_account_id', $id))
            ->when($v['trunk'] ?? null, fn ($q, $id) => $q->where('trunk_id', $id))
            ->when($v['number'] ?? null, fn ($q, $n) => $q->where(fn ($q) => $q->where('destination', 'like', "{$n}%")->orWhere('callee', 'like', "%{$n}%")))
            ->when($v['caller'] ?? null, fn ($q, $n) => $q->where('caller', 'like', "%{$n}%"))
            ->when(($v['status'] ?? null) === 'answered', fn ($q) => $q->whereNotNull('answered_at'))
            ->when(($v['status'] ?? null) === 'failed', fn ($q) => $q->whereNull('answered_at')->where('attempts', '>', 0))
            // Rejected = refused before any vendor was tried (no funds, blocked, unknown...).
            ->when(($v['status'] ?? null) === 'rejected', fn ($q) => $q->whereNull('answered_at')->where('attempts', 0))
            ->when(($v['direction'] ?? null) === 'incoming', fn ($q) => $q->whereNotNull('did'))
            ->when(($v['direction'] ?? null) === 'outgoing', fn ($q) => $q->whereNull('did')->where('direction', '!=', 'pbx_local'))
            ->when(($v['direction'] ?? null) === 'internal', fn ($q) => $q->where('direction', 'pbx_local'))
            ->when($v['sip'] ?? null, fn ($q, $code) => $q->where('sip_code', $code))
            ->when($v['reason'] ?? null, fn ($q, $r) => $q->where('hangup_cause', $r));
    }

    /** Query-string form, for links and the export URL. */
    public function query(): array
    {
        return ['from' => $this->from->toIso8601String(), 'to' => $this->to->toIso8601String(), ...$this->values];
    }
}
