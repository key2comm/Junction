<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Emergency numbers (911, 933, 988) skip the funds check: a prepaid customer
 * with no balance, or a postpaid one over the credit limit, can still make
 * them, and they're never cut off for running out of funds. They're still
 * rated and billed from the tariff after the call.
 *
 * softswitch_route(): as in 000036, with the call treated like one the plan
 * covers (no funds needed, none reserved, no time limit from the balance).
 */
return new class extends Migration
{
    // This migration only changed softswitch_route(), which now lives in
    // database/sql/softswitch_route.sql (applied after every migrate). Kept,
    // empty, because servers have it in their migrations table.
    public function up(): void {}

    public function down(): void {}
};
