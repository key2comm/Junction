<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The "Not assigned" DID status is now called "Free": no customer yet, so it
 * can be given to any customer. Only the stored value and the check's name change.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE pbx_dids DROP CONSTRAINT IF EXISTS pbx_dids_unassigned_check');
        DB::update("UPDATE pbx_dids SET status = 'free' WHERE status = 'unassigned'");
        // A DID without a customer must be Free, and the other way round.
        DB::statement("ALTER TABLE pbx_dids ADD CONSTRAINT pbx_dids_free_check CHECK ((status = 'free') = (account_id IS NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pbx_dids DROP CONSTRAINT IF EXISTS pbx_dids_free_check');
        DB::update("UPDATE pbx_dids SET status = 'unassigned' WHERE status = 'free'");
        DB::statement("ALTER TABLE pbx_dids ADD CONSTRAINT pbx_dids_unassigned_check CHECK ((status = 'unassigned') = (account_id IS NULL))");
    }
};
