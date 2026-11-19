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
    private const BUSY = 'bank_expenses_bank_movement_busy';

    private const CLASSIFIED = 'bank_expenses_bank_movement_classified';

    private const AMOUNT = 'bank_expenses_amount_mismatch';

    private const CREDIT = 'bank_expenses_credit';

    public function up(): void
    {
        // One classified expense per charge: the whole charge becomes spending, never a part of it.
        Schema::create('bank_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_movement_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('category');
            $table->string('description');
            // The absolute value of the charge, copied when it is classified, so the report never
            // re-derives it. The bank movement keeps its own sign: this table never changes it.
            $table->unsignedInteger('amount_cents');
            // One random key per rendered form: a repeated submit is not a second classification.
            $table->uuid('submission_key')->unique();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            // Read path of /bank and /saldos: active classifications only.
            $table->index(['voided_at']);
        });

        // A charge holds at most one live expense. Voided rows drop out of the index, so voiding
        // frees the charge again while the history stays. The check in code can be raced by two
        // simultaneous submissions, so the database holds the rule too.
        DB::statement(
            'CREATE UNIQUE INDEX bank_expenses_active_bank_movement_unique
             ON bank_expenses (bank_movement_id)
             WHERE voided_at IS NULL'
        );

        $this->triggers();
    }

    /**
     * A classification only fits a complete charge that no payment and no other destination uses.
     */
    private function triggers(): void
    {
        $credit = self::CREDIT;
        $amount = self::AMOUNT;
        $busy = self::BUSY;
        $classified = self::CLASSIFIED;

        // The same checks are needed on update: un-voiding a classification must not land on a
        // movement that another destination took meanwhile, nor above its own amount.
        DB::statement(<<<SQL
            CREATE TRIGGER bank_expenses_complete_insert
            BEFORE INSERT ON bank_expenses
            WHEN NEW.voided_at IS NULL
            BEGIN
                SELECT RAISE(ABORT, '{$credit}')
                WHERE NEW.amount_cents <= 0;

                SELECT RAISE(ABORT, '{$credit}')
                WHERE NOT EXISTS (
                    SELECT 1 FROM bank_movements movement
                    WHERE movement.id = NEW.bank_movement_id AND movement.amount_cents < 0
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

                SELECT RAISE(ABORT, '{$classified}')
                WHERE EXISTS (
                    SELECT 1 FROM bank_expenses expense
                    WHERE expense.bank_movement_id = NEW.bank_movement_id AND expense.voided_at IS NULL
                );
            END
            SQL);

        DB::statement(<<<SQL
            CREATE TRIGGER bank_expenses_complete_update
            BEFORE UPDATE OF amount_cents, bank_movement_id, voided_at ON bank_expenses
            WHEN NEW.voided_at IS NULL
            BEGIN
                SELECT RAISE(ABORT, '{$credit}')
                WHERE NEW.amount_cents <= 0;

                SELECT RAISE(ABORT, '{$credit}')
                WHERE NOT EXISTS (
                    SELECT 1 FROM bank_movements movement
                    WHERE movement.id = NEW.bank_movement_id AND movement.amount_cents < 0
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

                SELECT RAISE(ABORT, '{$classified}')
                WHERE EXISTS (
                    SELECT 1 FROM bank_expenses expense
                    WHERE expense.bank_movement_id = NEW.bank_movement_id
                        AND expense.voided_at IS NULL
                        AND expense.id <> NEW.id
                );
            END
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS bank_expenses_complete_insert');
        DB::statement('DROP TRIGGER IF EXISTS bank_expenses_complete_update');
        DB::statement('DROP INDEX IF EXISTS bank_expenses_active_bank_movement_unique');

        Schema::dropIfExists('bank_expenses');
    }
};
