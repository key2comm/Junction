<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The routing engine, as PostgreSQL functions. FreeSWITCH (Lua) and the admin
 * UI's route tester call the same code, so what the UI shows is what calls do.
 *
 *   softswitch_identify(source_ip, number)        -> which customer sent the call
 *   softswitch_route(account, number, uuid, ...)  -> rejection reason, or the
 *                                                    ordered vendor attempts
 *   softswitch_release(uuid)                      -> free a call's fund reservation
 *
 * The functions are SECURITY DEFINER: FreeSWITCH's database role is granted
 * EXECUTE on them only (by the installer) and cannot read any table directly.
 *
 * Funds: a call reserves the cost of its maximum duration in active_calls while
 * it is up, so concurrent calls cannot spend the same balance. Available funds
 * = balance + credit limit - reservations - rated usage not yet posted to the
 * ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE UNLOGGED TABLE active_calls (
                uuid uuid PRIMARY KEY,
                account_id bigint NOT NULL,
                reserved numeric(16, 6) NOT NULL,
                max_duration integer NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX active_calls_account_id_idx ON active_calls (account_id);

            -- migrate:fresh drops tables but not types; CASCADE also drops the
            -- function that returns it (recreated below).
            DROP TYPE IF EXISTS softswitch_route_row CASCADE;
            CREATE TYPE softswitch_route_row AS (
                status text,              -- 'ok' or 'reject'
                reason text,              -- reject reason (see below), null when ok
                attempt integer,          -- vendor attempt number (1 = first trunk to try)
                gateway_order integer,    -- order of gateways within the attempt
                trunk_id bigint,
                gateway_id bigint,
                gateway_name text,
                dial_number text,         -- number as sent to this trunk
                destination text,         -- normalised digits
                buy_rate_id bigint,
                buy_prefix text,
                buy_rate numeric,
                sell_rate_id bigint,
                sell_prefix text,
                sell_rate numeric,
                max_duration integer,     -- seconds the customer can afford (capped)
                trunk_max_channels integer,
                trunk_max_cps numeric
            );

            -- Which customer sent a call: enabled IP/CIDR match plus tech prefix.
            -- Longest tech prefix wins, then the most specific IP range.
            CREATE OR REPLACE FUNCTION softswitch_identify(p_ip inet, p_number text)
            RETURNS TABLE (account_id bigint, tech_prefix text, number text, max_channels integer, max_cps numeric)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public, pg_temp AS $$
                SELECT a.id, i.tech_prefix::text, substr(p_number, length(i.tech_prefix) + 1), a.max_channels, a.max_cps
                FROM account_ips i
                JOIN accounts a ON a.id = i.account_id
                WHERE i.enabled AND a.is_customer AND p_ip <<= i.ip
                  AND (i.tech_prefix = '' OR left(p_number, length(i.tech_prefix)) = i.tech_prefix)
                ORDER BY length(i.tech_prefix) DESC, masklen(i.ip) DESC
                LIMIT 1
            $$;

            -- Route a call. Reject reasons:
            --   unknown_customer, account_inactive, invalid_number, blocked_destination,
            --   no_sell_rate, no_routing_plan, insufficient_funds, no_route, negative_margin
            -- When p_uuid is given and the call is routable, its funds are reserved.
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
                           t.max_channels, t.max_cps, br.id AS buy_rate_id, br.prefix AS buy_prefix, br.rate AS buy_rate
                    FROM routing_plan_routes rr
                    JOIN trunks t ON t.id = rr.trunk_id
                    JOIN LATERAL (
                        SELECT x.id, x.prefix, x.rate FROM rates x
                        WHERE x.rate_deck_id = t.rate_deck_id AND x.prefix = ANY (prefixes)
                          AND x.effective_from <= p_at AND (x.effective_to IS NULL OR x.effective_to > p_at)
                        ORDER BY length(x.prefix) DESC, x.effective_from DESC
                        LIMIT 1
                    ) br ON true
                    WHERE rr.routing_plan_id = plan.id AND rr.enabled AND t.enabled AND rr.prefix = route_prefix
                      AND br.rate <= sell.rate
                      AND EXISTS (SELECT 1 FROM gateways g WHERE g.trunk_id = t.id AND g.enabled)
                ), ranked AS (
                    SELECT c.*, row_number() OVER (ORDER BY
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
                       gws.max_channels, gws.max_cps
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

            CREATE OR REPLACE FUNCTION softswitch_release(p_uuid uuid) RETURNS void
            LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path = public, pg_temp AS $$
                DELETE FROM active_calls WHERE uuid = p_uuid
            $$;

            REVOKE ALL ON FUNCTION softswitch_identify(inet, text) FROM PUBLIC;
            REVOKE ALL ON FUNCTION softswitch_route(bigint, text, uuid, timestamptz, integer) FROM PUBLIC;
            REVOKE ALL ON FUNCTION softswitch_release(uuid) FROM PUBLIC;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS softswitch_release(uuid);
            DROP FUNCTION IF EXISTS softswitch_route(bigint, text, uuid, timestamptz, integer);
            DROP FUNCTION IF EXISTS softswitch_identify(inet, text);
            DROP TYPE IF EXISTS softswitch_route_row;
            DROP TABLE IF EXISTS active_calls;
        SQL);
    }
};
