<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A movement can be reconciled once while its payment is active. The check in code can
        // be raced by two simultaneous submissions, so the database holds the rule too:
        // voided payments drop out of the index and free the movement again.
        DB::statement(
            'CREATE UNIQUE INDEX payments_active_bank_movement_unique
             ON payments (bank_movement_id)
             WHERE bank_movement_id IS NOT NULL AND voided_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payments_active_bank_movement_unique');
    }
};
