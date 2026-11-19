<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Abort messages the application looks for when it turns a constraint error into a validation error.
     */
    private const BUSY = 'cash_transfers_bank_movement_busy';

    private const TRANSFERRED = 'cash_transfers_bank_movement_transferred';

    private const AMOUNT = 'cash_transfers_amount_mismatch';

    private const DIRECTION = 'cash_transfers_direction_mismatch';

    public function up(): void
    {
        // A transfer is money moving between the two places the association keeps it. The statement
        // already moved it in the bank, so the row only records where it went and moves the cash.
        Schema::create('cash_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_movement_id')->constrained();
            $table->foreignId('user_id')->constrained();
            // to_cash: a charge of the statement funded the safe. to_bank: a credit took cash to the bank.
            $table->string('direction');
            $table->string('description');
            // The absolute value of the movement, copied when it is marked, so no figure carries a sign twice.
            $table->unsignedInteger('amount_cents');
            $table->uuid('submission_key')->unique();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->index(['voided_at']);
        });

        // One live transfer per movement, so voiding frees it and the history stays.
        DB::statement(
            'CREATE UNIQUE INDEX cash_transfers_active_bank_movement_unique
             ON cash_transfers (bank_movement_id)
             WHERE voided_at IS NULL'
        );

        $this->triggers();
    }

    /**
     * A transfer only fits a complete movement that no other destination uses, and its direction
     * must match the sign of the statement: a charge can only fund the safe, a credit can only
     * take cash to the bank.
     */
    private function triggers(): void
    {
        $busy = self::BUSY;
        $transferred = self::TRANSFERRED;
        $amount = self::AMOUNT;
        $direction = self::DIRECTION;

        foreach (['INSERT', 'UPDATE'] as $event) {
            $exclude = $event === 'INSERT' ? '' : ' AND transfer.id <> NEW.id';
            $when = $event === 'UPDATE' ? ' OF amount_cents, bank_movement_id, direction, voided_at' : '';
            $name = $event === 'INSERT' ? 'cash_transfers_complete_insert' : 'cash_transfers_complete_update';

            DB::statement(<<<SQL
                CREATE TRIGGER {$name}
                BEFORE {$event}{$when} ON cash_transfers
                WHEN NEW.voided_at IS NULL
                BEGIN
                    SELECT RAISE(ABORT, '{$direction}')
                    WHERE NEW.direction NOT IN ('to_cash', 'to_bank');

                    SELECT RAISE(ABORT, '{$direction}')
                    WHERE NOT EXISTS (
                        SELECT 1 FROM bank_movements movement
                        WHERE movement.id = NEW.bank_movement_id
                            AND (
                                (NEW.direction = 'to_cash' AND movement.amount_cents < 0)
                                OR (NEW.direction = 'to_bank' AND movement.amount_cents > 0)
                            )
                    );

                    SELECT RAISE(ABORT, '{$amount}')
                    WHERE NEW.amount_cents <> (
                        SELECT ABS(movement.amount_cents) FROM bank_movements movement WHERE movement.id = NEW.bank_movement_id
                    );

                    SELECT RAISE(ABORT, '{$busy}')
                    WHERE EXISTS (
                        SELECT 1 FROM payments payment
                        WHERE payment.bank_movement_id = NEW.bank_movement_id AND payment.voided_at IS NULL
                    );

                    SELECT RAISE(ABORT, '{$busy}')
                    WHERE EXISTS (
                        SELECT 1 FROM other_incomes other
                        WHERE other.bank_movement_id = NEW.bank_movement_id AND other.voided_at IS NULL
                    );

                    SELECT RAISE(ABORT, '{$busy}')
                    WHERE EXISTS (
                        SELECT 1 FROM bank_expenses expense
                        WHERE expense.bank_movement_id = NEW.bank_movement_id AND expense.voided_at IS NULL
                    );

                    SELECT RAISE(ABORT, '{$transferred}')
                    WHERE EXISTS (
                        SELECT 1 FROM cash_transfers transfer
                        WHERE transfer.bank_movement_id = NEW.bank_movement_id
                            AND transfer.voided_at IS NULL{$exclude}
                    );
                END
                SQL);
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cash_transfers_complete_insert');
        DB::statement('DROP TRIGGER IF EXISTS cash_transfers_complete_update');
        DB::statement('DROP INDEX IF EXISTS cash_transfers_active_bank_movement_unique');

        Schema::dropIfExists('cash_transfers');
    }
};
