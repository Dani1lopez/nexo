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
    private const BUSY = 'other_incomes_bank_movement_busy';

    private const CLASSIFIED = 'other_incomes_bank_movement_classified';

    private const AMOUNT = 'other_incomes_amount_mismatch';

    private const CHARGE = 'other_incomes_charge';

    private const MIXED = 'payments_bank_movement_other_income';

    public function up(): void
    {
        // One classification per movement: the whole credit becomes other income, never a part of it.
        Schema::create('other_incomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_movement_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('category');
            $table->string('description');
            // Copied from the movement when it is classified, so the report never re-derives it.
            $table->unsignedInteger('amount_cents');
            // One random key per rendered form: a repeated submit is not a second classification.
            $table->uuid('submission_key')->unique();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
        });

        // A movement holds at most one live classification. Voided rows drop out of the index, so
        // voiding frees the credit again while the history stays. The check in code can be raced
        // by two simultaneous submissions, so the database holds the rule too.
        DB::statement(
            'CREATE UNIQUE INDEX other_incomes_active_bank_movement_unique
             ON other_incomes (bank_movement_id)
             WHERE voided_at IS NULL'
        );

        $this->classificationTriggers();
        $this->paymentTriggers();
    }

    /**
     * A classification only fits a complete credit that no payment is using.
     */
    private function classificationTriggers(): void
    {
        $charge = self::CHARGE;
        $amount = self::AMOUNT;
        $busy = self::BUSY;
        $classified = self::CLASSIFIED;

        // The same checks are needed on update: un-voiding a classification must not land on money
        // that a due payment took meanwhile, nor above the movement again.
        DB::statement(<<<SQL
            CREATE TRIGGER other_incomes_complete_insert
            BEFORE INSERT ON other_incomes
            WHEN NEW.voided_at IS NULL
            BEGIN
                SELECT RAISE(ABORT, '{$charge}')
                WHERE NEW.amount_cents <= 0;

                SELECT RAISE(ABORT, '{$amount}')
                WHERE NEW.amount_cents <> (
                    SELECT movement.amount_cents FROM bank_movements movement WHERE movement.id = NEW.bank_movement_id
                );

                SELECT RAISE(ABORT, '{$busy}')
                WHERE EXISTS (
                    SELECT 1 FROM payments payment
                    WHERE payment.bank_movement_id = NEW.bank_movement_id AND payment.voided_at IS NULL
                );

                SELECT RAISE(ABORT, '{$classified}')
                WHERE EXISTS (
                    SELECT 1 FROM other_incomes other
                    WHERE other.bank_movement_id = NEW.bank_movement_id AND other.voided_at IS NULL
                );
            END
            SQL);

        DB::statement(<<<SQL
            CREATE TRIGGER other_incomes_complete_update
            BEFORE UPDATE OF amount_cents, bank_movement_id, voided_at ON other_incomes
            WHEN NEW.voided_at IS NULL
            BEGIN
                SELECT RAISE(ABORT, '{$charge}')
                WHERE NEW.amount_cents <= 0;

                SELECT RAISE(ABORT, '{$amount}')
                WHERE NEW.amount_cents <> (
                    SELECT movement.amount_cents FROM bank_movements movement WHERE movement.id = NEW.bank_movement_id
                );

                SELECT RAISE(ABORT, '{$busy}')
                WHERE EXISTS (
                    SELECT 1 FROM payments payment
                    WHERE payment.bank_movement_id = NEW.bank_movement_id AND payment.voided_at IS NULL
                );

                SELECT RAISE(ABORT, '{$classified}')
                WHERE EXISTS (
                    SELECT 1 FROM other_incomes other
                    WHERE other.bank_movement_id = NEW.bank_movement_id
                        AND other.voided_at IS NULL
                        AND other.id <> NEW.id
                );
            END
            SQL);
    }

    /**
     * A payment never takes money that is classified as other income, through any write path.
     */
    private function paymentTriggers(): void
    {
        $mixed = self::MIXED;

        DB::statement(<<<SQL
            CREATE TRIGGER payments_bank_movement_other_income_insert
            BEFORE INSERT ON payments
            WHEN NEW.bank_movement_id IS NOT NULL AND NEW.voided_at IS NULL
            BEGIN
                SELECT RAISE(ABORT, '{$mixed}')
                WHERE EXISTS (
                    SELECT 1 FROM other_incomes other
                    WHERE other.bank_movement_id = NEW.bank_movement_id AND other.voided_at IS NULL
                );
            END
            SQL);

        DB::statement(<<<SQL
            CREATE TRIGGER payments_bank_movement_other_income_update
            BEFORE UPDATE OF amount_cents, bank_movement_id, voided_at ON payments
            WHEN NEW.bank_movement_id IS NOT NULL AND NEW.voided_at IS NULL
            BEGIN
                SELECT RAISE(ABORT, '{$mixed}')
                WHERE EXISTS (
                    SELECT 1 FROM other_incomes other
                    WHERE other.bank_movement_id = NEW.bank_movement_id AND other.voided_at IS NULL
                );
            END
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS payments_bank_movement_other_income_update');
        DB::statement('DROP TRIGGER IF EXISTS payments_bank_movement_other_income_insert');
        DB::statement('DROP TRIGGER IF EXISTS other_incomes_complete_update');
        DB::statement('DROP TRIGGER IF EXISTS other_incomes_complete_insert');
        DB::statement('DROP INDEX IF EXISTS other_incomes_active_bank_movement_unique');

        Schema::dropIfExists('other_incomes');
    }
};
