<?php

use Illuminate\Database\Migrations\Migration;

/**
 * The 3-digit service numbers (211, 311, 511, 711, 811, 911, 933, 988) only
 * match a rate for that exact number. Otherwise 911 matched a carrier's India
 * rate (91), 988 Iran (98) and so on, so any carrier with those countries
 * could get an emergency call. Now only carriers whose rates include the
 * exact number are used, and the customer's tariff needs that exact number too
 * (otherwise the call is refused: no rate). LCR group rules and blocked
 * prefixes likewise only match the exact number (or an LCR rule for everything).
 *
 * Emergency numbers (911, 933, 988) also go to a carrier that costs more than
 * the customer's rate (so a 0 rate in the tariff works): never refused for margin.
 *
 * softswitch_route(): as in 000037, with one change to the prefixes it matches.
 */
return new class extends Migration
{
    // This migration only changed softswitch_route(), which now lives in
    // database/sql/softswitch_route.sql (applied after every migrate). Kept,
    // empty, because servers have it in their migrations table.
    public function up(): void {}

    public function down(): void {}
};
