<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quality-aware routing.
 *
 * trunk_quality: last hour's attempts/answers/PDD per trunk (prefix '') and per
 *   trunk + buy prefix, refreshed every minute (App\Services\TrunkQuality) so the
 *   routing function reads a small indexed table instead of raw attempts.
 * routing_plans.quality_*: per-plan switch and thresholds. When on, trunks
 *   below the bar are tried after healthy ones (never dropped).
 * softswitch_route(): same as before plus that ordering, and a new last column
 *   quality_demoted so the route tester can show why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trunk_quality', function (Blueprint $table) {
            $table->foreignId('trunk_id')->constrained()->cascadeOnDelete();
            $table->string('prefix', 32)->default('');
            $table->unsignedInteger('attempts');
            $table->unsignedInteger('answered');
            $table->unsignedInteger('pdd_count');
            $table->unsignedInteger('pdd_avg')->nullable();
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['trunk_id', 'prefix']);
        });

        Schema::table('routing_plans', function (Blueprint $table) {
            $table->boolean('quality_routing')->default(false)->after('max_attempts');
            $table->decimal('quality_min_asr', 5, 2)->default(10)->after('quality_routing');
            $table->unsignedInteger('quality_max_pdd_ms')->default(10000)->after('quality_min_asr');
            $table->unsignedInteger('quality_min_attempts')->default(30)->after('quality_max_pdd_ms');
        });

        DB::unprepared(<<<'SQL'
            ALTER TYPE softswitch_route_row ADD ATTRIBUTE quality_demoted boolean;

            CREATE OR REPLACE FUNCTION softswitch_route(
                p_account_id bigint,
                p_number text,
                p_uuid uuid DEFAULT NULL,
                p_at timestamptz DEFAULT now(),
                p_max_duration integer DEFAULT 14400
            ) RETURNS SETOF softswitch_route_row
            LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path = public, pg_temp AS $$
            DECLARE
                r softswitch_route_row;
                acct accounts%ROWTYPE;
                plan routing_plans%ROWTYPE;
                sell rates%ROWTYPE;
                num text;
                prefixes text[];
                available numeric;
                maxdur integer;
                route_prefix text;
            BEGIN
                SELECT * INTO acct FROM accounts WHERE id = p_account_id;
                IF NOT FOUND OR NOT acct.is_customer THEN
                    r.status := 'reject'; r.reason := 'unknown_customer'; RETURN NEXT r; RETURN;
                END IF;
                IF acct.status <> 'active' THEN
                    r.status := 'reject'; r.reason := 'account_inactive'; RETURN NEXT r; RETURN;
                END IF;

                num := regexp_replace(coalesce(p_number, ''), '\D', '', 'g');
                IF num = '' OR length(num) > 32 THEN
                    r.status := 'reject'; r.reason := 'invalid_number'; RETURN NEXT r; RETURN;
                END IF;
                prefixes := ARRAY(SELECT left(num, n) FROM generate_series(length(num), 1, -1) AS n);
                r.destination := num;

                IF EXISTS (SELECT 1 FROM blocked_prefixes b
                           WHERE (b.account_id IS NULL OR b.account_id = acct.id) AND b.prefix = ANY (prefixes)) THEN
                    r.status := 'reject'; r.reason := 'blocked_destination'; RETURN NEXT r; RETURN;
                END IF;

                SELECT x.* INTO sell FROM rates x
                WHERE x.rate_deck_id = acct.sell_rate_deck_id AND x.prefix = ANY (prefixes)
                  AND x.effective_from <= p_at AND (x.effective_to IS NULL OR x.effective_to > p_at)
                ORDER BY length(x.prefix) DESC, x.effective_from DESC
                LIMIT 1;
                IF NOT FOUND THEN
                    r.status := 'reject'; r.reason := 'no_sell_rate'; RETURN NEXT r; RETURN;
                END IF;
                r.sell_rate_id := sell.id;
                r.sell_prefix := sell.prefix;
                r.sell_rate := sell.rate;

                SELECT * INTO plan FROM routing_plans WHERE id = acct.routing_plan_id;
                IF NOT FOUND THEN
                    r.status := 'reject'; r.reason := 'no_routing_plan'; RETURN NEXT r; RETURN;
                END IF;

                -- Funds. Serialise per account while reserving so two calls can't both
                -- see the same balance.
                IF p_uuid IS NOT NULL THEN
                    PERFORM pg_advisory_xact_lock(1936092008, acct.id::integer);
                END IF;
                available := acct.balance + acct.credit_limit
                    - coalesce((SELECT sum(c.reserved) FROM active_calls c WHERE c.account_id = acct.id AND c.uuid IS DISTINCT FROM p_uuid), 0)
                    - coalesce((SELECT sum(d.sell_cost) FROM cdrs d WHERE d.customer_account_id = acct.id AND d.posted_at IS NULL AND d.sell_cost IS NOT NULL), 0);

                IF sell.rate = 0 THEN
                    maxdur := p_max_duration;
                    IF available < sell.connect_fee THEN
                        r.status := 'reject'; r.reason := 'insufficient_funds'; RETURN NEXT r; RETURN;
                    END IF;
                ELSE
                    maxdur := floor((available - sell.connect_fee) * 60 / sell.rate);
                    IF maxdur < sell.initial_increment THEN
                        r.status := 'reject'; r.reason := 'insufficient_funds'; RETURN NEXT r; RETURN;
                    END IF;
                    -- Only whole billing increments are affordable.
                    maxdur := sell.initial_increment + ((maxdur - sell.initial_increment) / sell.increment) * sell.increment;
                    maxdur := least(maxdur, p_max_duration);
                END IF;
                r.max_duration := maxdur;

                -- The longest routed prefix that has a usable trunk decides the candidates.
                SELECT rr.prefix INTO route_prefix
                FROM routing_plan_routes rr JOIN trunks t ON t.id = rr.trunk_id
                WHERE rr.routing_plan_id = plan.id AND rr.enabled AND t.enabled
                  AND (rr.prefix = '' OR rr.prefix = ANY (prefixes))
                  AND EXISTS (SELECT 1 FROM gateways g WHERE g.trunk_id = t.id AND g.enabled)
                ORDER BY length(rr.prefix) DESC
                LIMIT 1;
                IF NOT FOUND THEN
                    r.status := 'reject'; r.reason := 'no_route'; RETURN NEXT r; RETURN;
                END IF;

                RETURN QUERY
                WITH cand AS (
                    SELECT rr.trunk_id, rr.priority, rr.weight, t.tech_prefix, t.strip_digits,
                           t.max_channels, t.max_cps, br.id AS buy_rate_id, br.prefix AS buy_prefix, br.rate AS buy_rate,
                           -- Quality-aware routing: a trunk performing below the plan's bar over
                           -- the last hour (destination-level figures when there are enough,
                           -- else the trunk's overall figures) is tried after healthy ones.
                           CASE WHEN NOT plan.quality_routing THEN false ELSE coalesce(
                               CASE WHEN qp.attempts >= plan.quality_min_attempts THEN
                                   qp.answered * 100.0 / qp.attempts < plan.quality_min_asr
                                   OR (qp.pdd_count >= 10 AND qp.pdd_avg > plan.quality_max_pdd_ms) END,
                               CASE WHEN qt.attempts >= plan.quality_min_attempts THEN
                                   qt.answered * 100.0 / qt.attempts < plan.quality_min_asr
                                   OR (qt.pdd_count >= 10 AND qt.pdd_avg > plan.quality_max_pdd_ms) END,
                               false) END AS demoted
                    FROM routing_plan_routes rr
                    JOIN trunks t ON t.id = rr.trunk_id
                    JOIN LATERAL (
                        SELECT x.id, x.prefix, x.rate FROM rates x
                        WHERE x.rate_deck_id = t.rate_deck_id AND x.prefix = ANY (prefixes)
                          AND x.effective_from <= p_at AND (x.effective_to IS NULL OR x.effective_to > p_at)
                        ORDER BY length(x.prefix) DESC, x.effective_from DESC
                        LIMIT 1
                    ) br ON true
                    LEFT JOIN trunk_quality qp ON qp.trunk_id = t.id AND qp.prefix = br.prefix
                    LEFT JOIN trunk_quality qt ON qt.trunk_id = t.id AND qt.prefix = ''
                    WHERE rr.routing_plan_id = plan.id AND rr.enabled AND t.enabled AND rr.prefix = route_prefix
                      AND br.rate <= sell.rate
                      AND EXISTS (SELECT 1 FROM gateways g WHERE g.trunk_id = t.id AND g.enabled)
                ), ranked AS (
                    SELECT c.*, row_number() OVER (ORDER BY
                        c.demoted,
                        CASE WHEN plan.mode = 'lcr' THEN c.buy_rate ELSE 0 END,
                        CASE WHEN plan.mode = 'weight' THEN -ln(1 - random()) / c.weight ELSE 0 END,
                        c.priority, c.buy_rate, c.trunk_id) AS attempt
                    FROM cand c
                ), gws AS (
                    SELECT k.*, g.id AS gateway_id, g.name AS gateway_name,
                           row_number() OVER (PARTITION BY k.trunk_id ORDER BY g.priority, -ln(1 - random()) / g.weight, g.id) AS gateway_order
                    FROM ranked k JOIN gateways g ON g.trunk_id = k.trunk_id AND g.enabled
                    WHERE k.attempt <= plan.max_attempts
                )
                SELECT 'ok'::text, NULL::text, gws.attempt::integer, gws.gateway_order::integer, gws.trunk_id, gws.gateway_id,
                       gws.gateway_name::text, (gws.tech_prefix || substr(num, gws.strip_digits + 1))::text, num,
                       gws.buy_rate_id, gws.buy_prefix::text, gws.buy_rate, sell.id, sell.prefix::text, sell.rate, maxdur,
                       gws.max_channels, gws.max_cps, gws.demoted
                FROM gws
                ORDER BY gws.attempt, gws.gateway_order;

                IF FOUND THEN
                    IF p_uuid IS NOT NULL THEN
                        INSERT INTO active_calls (uuid, account_id, reserved, max_duration)
                        VALUES (p_uuid, acct.id, sell.connect_fee + sell.rate * maxdur / 60, maxdur)
                        ON CONFLICT (uuid) DO UPDATE SET reserved = EXCLUDED.reserved, max_duration = EXCLUDED.max_duration;
                    END IF;
                    RETURN;
                END IF;

                -- Nothing usable: say why.
                r.status := 'reject';
                r.reason := CASE WHEN EXISTS (
                        SELECT 1 FROM routing_plan_routes rr JOIN trunks t ON t.id = rr.trunk_id
                        JOIN rates x ON x.rate_deck_id = t.rate_deck_id AND x.prefix = ANY (prefixes)
                             AND x.effective_from <= p_at AND (x.effective_to IS NULL OR x.effective_to > p_at)
                        WHERE rr.routing_plan_id = plan.id AND rr.enabled AND t.enabled AND rr.prefix = route_prefix
                          AND x.rate > sell.rate
                          AND EXISTS (SELECT 1 FROM gateways g WHERE g.trunk_id = t.id AND g.enabled)
                    ) THEN 'negative_margin' ELSE 'no_route' END;
                RETURN NEXT r;
            END;
            $$;
        SQL);
    }

    public function down(): void
    {
        // The previous function body is restored by re-running the 000003 migration.
        Schema::table('routing_plans', fn (Blueprint $table) => $table->dropColumn(['quality_routing', 'quality_min_asr', 'quality_max_pdd_ms', 'quality_min_attempts']));
        Schema::dropIfExists('trunk_quality');
    }
};
