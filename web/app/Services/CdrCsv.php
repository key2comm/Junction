<?php

namespace App\Services;

use App\Http\Controllers\Admin\CdrController;
use App\Models\Cdr;
use App\Support\CdrFilters;

/**
 * Call records as CSV: the Calls → Call records export and Settings →
 * Scheduled reports write the same columns.
 */
class CdrCsv
{
    public const HEADER = ['started_at_utc', 'answered_at_utc', 'ended_at_utc', 'customer', 'source_ip', 'caller', 'callee',
        'destination', 'billsec', 'sip_code', 'hangup_cause', 'attempts', 'sell_prefix', 'sell_rate', 'sell_cost',
        'vendor', 'trunk', 'buy_prefix', 'buy_rate', 'buy_cost', 'margin', 'uuid', 'direction', 'did'];

    /**
     * @param  resource  $out
     * @param  (callable(): void)|null  $afterChunk  e.g. flush() when streaming
     * @return int rows written (not counting the header)
     */
    public static function write($out, CdrFilters $filters, ?callable $afterChunk = null): int
    {
        fputcsv($out, self::HEADER, escape: '');
        $count = 0;
        $filters->apply(Cdr::query())
            ->with(['customer:id,code', 'vendor:id,code', 'trunk:id,name'])
            ->chunkById(2000, function ($rows) use ($out, $afterChunk, &$count) {
                foreach ($rows as $c) {
                    fputcsv($out, array_map([CdrController::class, 'cell'], [
                        $c->started_at?->utc()->toIso8601String(), $c->answered_at?->utc()->toIso8601String(),
                        $c->ended_at?->utc()->toIso8601String(), $c->customer?->code, $c->source_ip, $c->caller, $c->callee,
                        $c->destination, $c->billsec, $c->sip_code, $c->hangup_cause, $c->attempts, $c->sell_prefix,
                        $c->sell_rate, $c->sell_cost, $c->vendor?->code, $c->trunk?->name, $c->buy_prefix, $c->buy_rate,
                        $c->buy_cost, $c->margin(), $c->uuid, $c->flow(), $c->did,
                    ]), escape: '');
                    $count++;
                }
                if ($afterChunk) {
                    $afterChunk();
                }
            });

        return $count;
    }
}
