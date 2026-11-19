<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Abort message the application looks for when it turns a constraint error into a validation error.
     */
    private const TRANSFERRED = 'bank_movement_has_transfer';

    /**
     * A movement has one destination. The transfer migration guards a transfer written over another
     * destination; these are the reciprocal guards, so a payment, another income or an expense
     * written after a transfer cannot leave the same movement with two destinations.
     */
    public function up(): void
    {
        $transferred = self::TRANSFERRED;

        foreach ([['payments', 'payment'], ['other_incomes', 'income'], ['bank_expenses', 'expense']] as [$table, $alias]) {
            foreach (['INSERT', 'UPDATE'] as $event) {
                $when = $event === 'UPDATE' ? ' OF amount_cents, bank_movement_id, voided_at' : '';

                DB::statement(<<<SQL
                    CREATE TRIGGER {$table}_bank_movement_transfer_{$event}
                    BEFORE {$event}{$when} ON {$table}
                    WHEN NEW.voided_at IS NULL AND NEW.bank_movement_id IS NOT NULL
                    BEGIN
                        SELECT RAISE(ABORT, '{$transferred}')
                        WHERE EXISTS (
                            SELECT 1 FROM cash_transfers transfer
                            WHERE transfer.bank_movement_id = NEW.bank_movement_id
                                AND transfer.voided_at IS NULL
                        );
                    END
                    SQL);
            }
        }
    }

    public function down(): void
    {
        foreach (['payments', 'other_incomes', 'bank_expenses'] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_bank_movement_transfer_INSERT");
            DB::statement("DROP TRIGGER IF EXISTS {$table}_bank_movement_transfer_UPDATE");
        }
    }
};
