<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Emergency and non-emergency 3-digit numbers are allowed under every
 * dialing rule: 211, 311, 511, 711, 811, 911, 933 and 988.
 *
 * Before this, Canada/USA-only customers were refused them because the
 * country lookup read them as country codes (911 as India +91, 988 as Iran
 * +98, 711 as Russia +7...). They are now Canada/USA calls, so unlimited or
 * included Canada/USA minutes on a billing profile cover them too.
 *
 * softswitch_route(): as in 000030, with that one change to the dialing rules check.
 */
return new class extends Migration
{
    // This migration only changed softswitch_route(), which now lives in
    // database/sql/softswitch_route.sql (applied after every migrate). Kept,
    // empty, because servers have it in their migrations table.
    public function up(): void {}

    public function down(): void {}
};
