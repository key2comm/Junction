<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Cdr;
use App\Models\Trunk;
use App\Services\Router;
use App\Support\CdrFilters;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Call records: list with filters and a summary, per-call detail with every
 * vendor attempt, and CSV export.
 */
class CdrController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = CdrFilters::fromRequest($request);

        $cdrs = $filters->apply(Cdr::query())
            ->with(['customer:id,code', 'vendor:id,code', 'trunk:id,name'])
            ->orderByDesc('started_at')->orderByDesc('id')
            ->simplePaginate(50)
            ->withQueryString()
            ->through(fn (Cdr $c) => [
                ...$c->only(['id', 'started_at', 'caller', 'callee', 'destination', 'billsec', 'sip_code', 'hangup_cause',
                    'attempts', 'sell_cost', 'buy_cost']),
                'answered' => $c->answered_at !== null,
                'customer' => $c->customer?->code,
                'vendor' => $c->vendor?->code,
                'trunk' => $c->trunk?->name,
                'margin' => $c->margin(),
            ]);

        return Inertia::render('cdrs/Index', [
            'cdrs' => $cdrs,
            'summary' => self::summary($filters),
            'filters' => $filters->query(),
            // Totals assume one billing currency: show the one most accounts use.
            'currency' => Account::query()->groupBy('currency')->orderByRaw('count(*) desc')->value('currency') ?? 'USD',
            'options' => [
                'customers' => Account::query()->customers()->orderBy('code')->get(['id', 'code', 'name']),
                'vendors' => Account::query()->vendors()->orderBy('code')->get(['id', 'code', 'name']),
                'trunks' => Trunk::query()->with('account:id,code')->orderBy('name')->get(['id', 'name', 'account_id'])
                    ->map(fn (Trunk $t) => ['id' => $t->id, 'label' => "{$t->account->code} / {$t->name}"]),
            ],
        ]);
    }

    public function show(Cdr $cdr): Response
    {
        $cdr->load(['customer:id,code,name,currency', 'vendor:id,code,name', 'trunk:id,name', 'gateway:id,name',
            'attemptLog' => fn ($q) => $q->orderBy('attempt'), 'attemptLog.trunk:id,name,account_id', 'attemptLog.trunk.account:id,code',
            'attemptLog.gateway:id,name']);

        return Inertia::render('cdrs/Show', [
            'cdr' => [
                ...$cdr->only(['id', 'uuid', 'direction', 'caller', 'callee', 'destination', 'started_at', 'answered_at', 'ended_at', 'billsec',
                    'sip_code', 'hangup_cause', 'attempts', 'sell_prefix', 'sell_rate', 'sell_cost', 'buy_prefix', 'buy_rate', 'buy_cost',
                    'rated_at', 'posted_at']),
                'source_ip' => $cdr->source_ip,
                'margin' => $cdr->margin(),
                'currency' => $cdr->customer?->currency ?? 'USD',
                'customer' => $cdr->customer?->only(['id', 'code', 'name']),
                'vendor' => $cdr->vendor?->only(['id', 'code', 'name']),
                'trunk' => $cdr->trunk?->only(['id', 'name']),
                'gateway' => $cdr->gateway?->name,
                'reason' => Router::REASONS[$cdr->hangup_cause] ?? null,
            ],
            'attempts' => $cdr->attemptLog->map(fn ($a) => [
                ...$a->only(['attempt', 'buy_prefix', 'buy_rate', 'started_at', 'ended_at', 'sip_code', 'hangup_cause']),
                'answered' => $a->answered_at !== null,
                'trunk' => $a->trunk ? "{$a->trunk->account->code} / {$a->trunk->name}" : null,
                'trunk_id' => $a->trunk_id,
                'gateway' => $a->gateway?->name,
            ]),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = CdrFilters::fromRequest($request);
        $name = sprintf('cdrs-%s-to-%s.csv', $filters->from->format('Ymd-Hi'), $filters->to->format('Ymd-Hi'));

        return response()->streamDownload(function () use ($filters) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['started_at_utc', 'answered_at_utc', 'ended_at_utc', 'customer', 'source_ip', 'caller', 'callee',
                'destination', 'billsec', 'sip_code', 'hangup_cause', 'attempts', 'sell_prefix', 'sell_rate', 'sell_cost',
                'vendor', 'trunk', 'buy_prefix', 'buy_rate', 'buy_cost', 'margin', 'uuid'], escape: '');

            $filters->apply(Cdr::query())
                ->with(['customer:id,code', 'vendor:id,code', 'trunk:id,name'])
                ->chunkById(2000, function ($rows) use ($out) {
                    foreach ($rows as $c) {
                        fputcsv($out, array_map([self::class, 'cell'], [
                            $c->started_at?->utc()->toIso8601String(), $c->answered_at?->utc()->toIso8601String(),
                            $c->ended_at?->utc()->toIso8601String(), $c->customer?->code, $c->source_ip, $c->caller, $c->callee,
                            $c->destination, $c->billsec, $c->sip_code, $c->hangup_cause, $c->attempts, $c->sell_prefix,
                            $c->sell_rate, $c->sell_cost, $c->vendor?->code, $c->trunk?->name, $c->buy_prefix, $c->buy_rate,
                            $c->buy_cost, $c->margin(), $c->uuid,
                        ]), escape: '');
                    }
                    flush();
                });
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Neutralise spreadsheet formulas in exported text (caller IDs and numbers
     * come from the network). Plain signed numbers like +15145550100 are kept.
     */
    public static function cell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '' || preg_match('/^[+-]?\d+(\.\d+)?$/', $value)) {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    public static function summary(CdrFilters $filters): array
    {
        $row = $filters->apply(Cdr::query())->toBase()->selectRaw(
            'count(*) AS calls,
             count(answered_at) AS answered,
             count(*) FILTER (WHERE attempts > 0) AS routed,
             coalesce(sum(billsec), 0) AS billsec,
             coalesce(sum(sell_cost), 0) AS sell,
             coalesce(sum(buy_cost), 0) AS buy'
        )->first();

        $answered = (int) $row->answered;

        return [
            'calls' => (int) $row->calls,
            'answered' => $answered,
            'rejected' => (int) $row->calls - (int) $row->routed,
            // ASR over calls that reached a carrier; refusals before routing don't count against carriers.
            'asr' => (int) $row->routed > 0 ? round($answered / (int) $row->routed * 100, 1) : null,
            'acd' => $answered > 0 ? (int) round((int) $row->billsec / $answered) : null,
            'minutes' => round((int) $row->billsec / 60, 1),
            // Exact decimals from PostgreSQL (never floats for money).
            'sell' => bcadd((string) $row->sell, '0', 6),
            'buy' => bcadd((string) $row->buy, '0', 6),
            'margin' => bcsub((string) $row->sell, (string) $row->buy, 6),
        ];
    }
}
