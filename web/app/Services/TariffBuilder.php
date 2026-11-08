<?php

namespace App\Services;

use App\Models\RateDeck;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds a tariff's rates from one or more Carrier Rates plus a markup.
 *
 * Every prefix in any source becomes a tariff prefix. For each, every source
 * that can carry it contributes its rate (its longest matching prefix, as the
 * router would pick), and the HIGHEST is marked up, so every carrier in the
 * customer's LCR group stays profitable and usable (the router skips carriers
 * costing more than the sell rate). Connect fee and billing increments come
 * from that same carrier rate; the destination name from an exact-prefix
 * match when there is one (the dearest such carrier's name).
 *
 * The rows are written straight into RateImporter's temp table (no PHP arrays,
 * so big decks don't use memory) and applied in "build" (replace) mode.
 */
class TariffBuilder
{
    /**
     * Fill rate_import_rows (see RateImporter::apply()) with the tariff's rates.
     *
     * @return int rows
     */
    public function load(RateDeck $tariff, CarbonImmutable $at): int
    {
        $sources = $tariff->sources()->pluck('rate_decks.id')->all();
        if ($sources === []) {
            return 0;
        }
        $at = $at->utc()->format('Y-m-d H:i:sP');
        $ids = '{'.implode(',', array_map('intval', $sources)).'}';
        $value = (string) $tariff->markup_value;
        $percent = $tariff->markup_type === 'percent' ? 'true' : 'false';

        return DB::affectingStatement(<<<'SQL'
            INSERT INTO rate_import_rows (prefix, destination, rate, connect_fee, initial_increment, increment, effective_from)
            WITH src AS (
                SELECT DISTINCT r.prefix FROM rates r
                WHERE r.rate_deck_id = ANY (?::bigint[]) AND r.effective_from <= ?::timestamptz
                  AND (r.effective_to IS NULL OR r.effective_to > ?::timestamptz)
            ), best AS (
                SELECT p.prefix, m.rate, m.connect_fee, m.initial_increment, m.increment, m.destination, m.matched
                FROM src p
                CROSS JOIN LATERAL (
                    SELECT DISTINCT ON (r.rate_deck_id) r.rate, r.connect_fee, r.initial_increment, r.increment,
                           r.destination, r.prefix AS matched
                    FROM rates r
                    WHERE r.rate_deck_id = ANY (?::bigint[])
                      AND r.prefix = ANY (ARRAY(SELECT left(p.prefix, n) FROM generate_series(1, length(p.prefix)) AS n))
                      AND r.effective_from <= ?::timestamptz AND (r.effective_to IS NULL OR r.effective_to > ?::timestamptz)
                    ORDER BY r.rate_deck_id, length(r.prefix) DESC, r.effective_from DESC
                ) m
            ), named AS (
                SELECT b.*, first_value(b.destination) OVER (
                           PARTITION BY b.prefix ORDER BY (b.matched = b.prefix) DESC, (b.destination IS NULL), b.rate DESC, b.destination
                       ) AS name
                FROM best b
            )
            SELECT DISTINCT ON (prefix) prefix, name AS destination,
                   round(CASE WHEN ?::boolean THEN rate * (1 + ?::numeric / 100) ELSE rate + ?::numeric END, 6) AS rate,
                   connect_fee, initial_increment, increment, ?::timestamptz
            FROM named
            ORDER BY prefix, rate DESC, connect_fee DESC
        SQL, [$ids, $at, $at, $ids, $at, $at, $percent, $value, $value, $at]);
    }
}
