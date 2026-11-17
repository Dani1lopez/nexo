<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Abort message the application looks for when it turns a constraint error into a validation error.
     */
    private const CAPACITY = 'payments_bank_movement_capacity: la suma de pagos vigentes supera el importe del movimiento';

    public function up(): void
    {
        // A movement can feed several payments since #34, so the old "one active payment" index is gone.
        // Its capacity is now its own amount: the sum of its active payments can never exceed it.
        DB::statement('DROP INDEX IF EXISTS payments_active_bank_movement_unique');

        // The check in code can be raced by two simultaneous submissions, so SQLite holds the rule too.
        // Voided payments drop out of the sum and free exactly that part of the movement again.
        $abort = self::CAPACITY;

        DB::statement(<<<SQL
            CREATE TRIGGER payments_bank_movement_capacity_insert
            BEFORE INSERT ON payments
            WHEN NEW.bank_movement_id IS NOT NULL AND NEW.voided_at IS NULL
            BEGIN
                SELECT RAISE(ABORT, '{$abort}')
                WHERE (
                    SELECT COALESCE(SUM(existing.amount_cents), 0)
                    FROM payments existing
                    WHERE existing.bank_movement_id = NEW.bank_movement_id AND existing.voided_at IS NULL
                ) + NEW.amount_cents > (
                    SELECT movement.amount_cents FROM bank_movements movement WHERE movement.id = NEW.bank_movement_id
                );
            END
            SQL);

        DB::statement(<<<SQL
            CREATE TRIGGER payments_bank_movement_capacity_update
            BEFORE UPDATE OF amount_cents, bank_movement_id ON payments
            WHEN NEW.bank_movement_id IS NOT NULL AND NEW.voided_at IS NULL
            BEGIN
                SELECT RAISE(ABORT, '{$abort}')
                WHERE (
                    SELECT COALESCE(SUM(existing.amount_cents), 0)
                    FROM payments existing
                    WHERE existing.bank_movement_id = NEW.bank_movement_id
                        AND existing.voided_at IS NULL
                        AND existing.id <> NEW.id
                ) + NEW.amount_cents > (
                    SELECT movement.amount_cents FROM bank_movements movement WHERE movement.id = NEW.bank_movement_id
                );
            END
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS payments_bank_movement_capacity_update');
        DB::statement('DROP TRIGGER IF EXISTS payments_bank_movement_capacity_insert');

        DB::statement(
            'CREATE UNIQUE INDEX payments_active_bank_movement_unique
             ON payments (bank_movement_id)
             WHERE bank_movement_id IS NOT NULL AND voided_at IS NULL'
        );
    }
};
