<?php

namespace Tests\Feature;

use App\Modules\Access\Models\User;
use App\Modules\Bank\Models\BankImport;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Bank\Models\OtherIncome;
use App\Modules\Fees\Actions\RegisterPayment;
use App\Modules\Fees\Actions\SetFeeYear;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\FeeYear;
use App\Modules\Fees\Models\Payment;
use App\Modules\Members\Models\Member;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\AuthTestCase;

/**
 * Issue #44, T1: the integrity foundation. A complete imported credit has exactly one destination,
 * dues or other income, and the database holds that rule even when two writes interleave.
 * The screen and the action that classify are later slices, so these proofs write the row directly.
 */
class OtherIncomeIntegrityTest extends AuthTestCase
{
    private function member(string $email = 'ana@example.test', string $name = 'Ana ficticia'): Member
    {
        return Member::create(['name' => $name, 'email' => $email, 'joined_on' => '2024-01-15']);
    }

    private function year(int $year = 2026, int $cents = 3000): FeeYear
    {
        return app(SetFeeYear::class)->handle($year, $cents);
    }

    private function dueFor(Member $member, FeeYear $year): Due
    {
        return Due::where('member_id', $member->id)->where('fee_year_id', $year->id)->firstOrFail();
    }

    private function treasury(): User
    {
        return User::where('email', 'tesoreria@example.test')->first()
            ?? User::provision('Persona ficticia', 'tesoreria@example.test', 'treasury-secret-value', 'treasury');
    }

    private function movement(int $cents, string $concept = 'DONACION MERCADILLO'): BankMovement
    {
        $importer = User::where('email', 'importador@example.test')->first()
            ?? User::provision('Importador ficticio', 'importador@example.test', 'importer-secret-value', 'treasury');

        $import = BankImport::forceCreate([
            'user_id' => $importer->id,
            'imported_count' => 1,
            'duplicate_count' => 0,
            'rejected_count' => 0,
        ]);

        return BankMovement::forceCreate([
            'bank_import_id' => $import->id,
            'occurred_on' => '2026-02-14',
            'amount_cents' => $cents,
            'concept' => $concept,
            'fingerprint' => BankMovement::fingerprint('2026-02-14', $cents, $concept),
        ]);
    }

    /** Writes a classification row; the defaults are valid and each case overrides what it needs. */
    private function classify(BankMovement $movement, array $overrides = []): OtherIncome
    {
        return OtherIncome::forceCreate(array_merge([
            'bank_movement_id' => $movement->id,
            'user_id' => $this->treasury()->id,
            'category' => OtherIncome::DONATIONS,
            'description' => 'Donación del mercadillo',
            'amount_cents' => $movement->amount_cents,
            'submission_key' => (string) Str::uuid(),
        ], $overrides));
    }

    /** Writes a payment on the credit through the table, the path the triggers have to police. */
    private function paymentFor(BankMovement $movement, Due $due, ?int $cents = null): Payment
    {
        return Payment::forceCreate([
            'due_id' => $due->id,
            'bank_movement_id' => $movement->id,
            'amount_cents' => $cents ?? $movement->amount_cents,
            'paid_on' => '2026-02-14',
            'method' => Payment::BANK,
            'submission_key' => (string) Str::uuid(),
        ]);
    }

    /** Voids a classification the way the later void action will: the row and its reason stay. */
    private function void(OtherIncome $income, string $reason = 'Se clasificó mal.'): void
    {
        DB::table('other_incomes')->where('id', $income->id)->update([
            'voided_at' => now(),
            'void_reason' => $reason,
            'updated_at' => now(),
        ]);
    }

    /**
     * Asserts that a write is aborted by the named SQLite guard and stores nothing.
     *
     * @param  callable(): mixed  $write
     */
    private function assertAborted(string $token, callable $write): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            $this->assertStringContainsString($token, $e->getMessage());

            return;
        }

        $this->fail("La escritura se aceptó: se esperaba el aborto {$token}.");
    }

    /** S1: a credit is only classified as a whole, never by a part of its amount. */
    public function test_the_database_refuses_a_partial_amount(): void
    {
        $credit = $this->movement(1500);

        $this->assertAborted('other_incomes_amount_mismatch', fn () => $this->classify($credit, ['amount_cents' => 500]));
        $this->assertAborted('other_incomes_amount_mismatch', fn () => $this->classify($credit, ['amount_cents' => 2000]));

        $this->assertSame(0, OtherIncome::count());
    }

    /** S1: a charge is not income, so it never becomes a classification. */
    public function test_the_database_refuses_a_charge_as_other_income(): void
    {
        $charge = $this->movement(-1500, 'RECIBO DEVUELTO');
        $credit = $this->movement(1500, 'DONACION MERCADILLO');

        // The guard is the non-positive amount: zero for the charge, a negative row for the credit.
        $this->assertAborted('other_incomes_charge', fn () => $this->classify($charge, ['amount_cents' => 0]));
        $this->assertAborted('other_incomes_charge', fn () => $this->classify($credit, ['amount_cents' => -100]));

        $this->assertSame(0, OtherIncome::count());
    }

    /** S1 and S5: money already spent on dues cannot be classified at the same time. */
    public function test_the_database_refuses_a_credit_fully_taken_by_a_payment(): void
    {
        $due = $this->dueFor($this->member(), $this->year(2026, 1500));
        $credit = $this->movement(1500);
        $this->paymentFor($credit, $due);

        $this->assertAborted('other_incomes_bank_movement_busy', fn () => $this->classify($credit));

        $this->assertSame(0, OtherIncome::count());
        $this->assertSame(1500, $due->fresh()->paidCents());
    }

    /** S1: a credit with only a part assigned is not whole either, so it stays out of other income. */
    public function test_the_database_refuses_a_credit_with_a_part_already_assigned(): void
    {
        $year = $this->year(2026, 1000);
        $ana = $this->dueFor($this->member(), $year);
        $luis = $this->dueFor($this->member('luis@example.test', 'Luis ficticio'), $year);
        $credit = $this->movement(2000, 'TRANSFERENCIA CONJUNTA');
        $this->paymentFor($credit, $ana, 1000);
        $this->paymentFor($credit, $luis, 1000);

        // Voiding one part leaves 10,00 € of the credit free but not the whole credit: still refused.
        DB::table('payments')->where('due_id', $ana->id)->update(['voided_at' => now()]);

        $this->assertAborted('other_incomes_bank_movement_busy', fn () => $this->classify($credit));

        $this->assertSame(0, OtherIncome::count());
        $this->assertSame(2, Payment::where('bank_movement_id', $credit->id)->count());
        $this->assertSame(0, $ana->fresh()->paidCents());
        $this->assertSame(1000, $luis->fresh()->paidCents());
    }

    /** S5: the same credit is never classified twice, so simultaneous submissions cannot double it. */
    public function test_the_database_refuses_a_second_active_classification(): void
    {
        $credit = $this->movement(1500);
        $first = $this->classify($credit);

        $this->assertAborted('other_incomes_bank_movement_classified', fn () => $this->classify($credit, [
            'description' => 'Envío simultáneo',
        ]));

        $this->assertSame(1, OtherIncome::count());
        $this->assertNull($first->fresh()->voided_at);
        $this->assertSame(1500, (int) OtherIncome::sum('amount_cents'));
    }

    /** S5 and S7, idempotency in the database: one submission key is one classification. */
    public function test_the_database_refuses_a_reused_submission_key(): void
    {
        $key = (string) Str::uuid();
        $this->classify($this->movement(1500, 'DONACION MERCADILLO'), ['submission_key' => $key]);

        $this->assertAborted('other_incomes', fn () => $this->classify(
            $this->movement(1200, 'ACTIVIDAD DE SOCIOS'),
            ['submission_key' => $key],
        ));

        $this->assertSame(1, OtherIncome::count());
    }

    /** S5: un-voiding a classification cannot take back money that a due already holds. */
    public function test_the_database_refuses_un_voiding_a_classification_when_dues_were_paid(): void
    {
        $due = $this->dueFor($this->member(), $this->year(2026, 1500));
        $credit = $this->movement(1500);
        $income = $this->classify($credit);
        $this->void($income, 'Vuelve a conciliarse');
        $this->paymentFor($credit, $due);

        $this->assertAborted('other_incomes_bank_movement_busy', fn () => DB::table('other_incomes')
            ->where('id', $income->id)
            ->update(['voided_at' => null]));

        $this->assertNotNull($income->fresh()->voided_at);
        $this->assertSame(1500, $due->fresh()->paidCents());
        $this->assertSame(1, Payment::where('bank_movement_id', $credit->id)->whereNull('voided_at')->count());
    }

    /** S5: a voided payment cannot be revived onto money that is classified as other income. */
    public function test_the_database_refuses_reviving_a_payment_on_a_classified_credit(): void
    {
        $due = $this->dueFor($this->member(), $this->year(2026, 1500));
        $credit = $this->movement(1500);
        $payment = $this->paymentFor($credit, $due);

        DB::table('payments')->where('id', $payment->id)->update(['voided_at' => now()]);
        $this->classify($credit);

        $this->assertAborted('payments_bank_movement_other_income', fn () => DB::table('payments')
            ->where('id', $payment->id)
            ->update(['voided_at' => null]));

        $this->assertNotNull($payment->fresh()->voided_at);
        $this->assertSame(0, $due->fresh()->paidCents());
        $this->assertSame(1, OtherIncome::whereNull('voided_at')->count());
    }

    /** S3 and S5: voiding frees the credit again, without deleting the history. */
    public function test_a_voided_classification_frees_the_credit_and_keeps_the_row(): void
    {
        $due = $this->dueFor($this->member(), $this->year(2026, 1500));
        $credit = $this->movement(1500);
        $income = $this->classify($credit);
        $this->void($income, 'Era la cuota de Ana');

        // The freed credit can go back to dues like any other, and the classification still exists.
        $this->paymentFor($credit, $due);

        $this->assertSame(1500, $due->fresh()->paidCents());
        $this->assertSame(1, OtherIncome::count());
        $this->assertSame('Era la cuota de Ana', $income->fresh()->void_reason);
        $this->assertSame(0, OtherIncome::whereNull('voided_at')->count());
        $this->assertSame(1, $credit->otherIncomes()->count());
    }

    /** S5: the unique active rule drops voided rows, so a voided credit can be classified again. */
    public function test_a_voided_classification_can_be_replaced_by_a_new_one(): void
    {
        $credit = $this->movement(1500);
        $income = $this->classify($credit);
        $this->void($income);

        $this->classify($credit, ['category' => OtherIncome::OTHER, 'description' => 'Otros ingresos del local']);

        $this->assertSame(2, OtherIncome::count());
        $this->assertSame(1, OtherIncome::whereNull('voided_at')->count());
        $this->assertSame('Otros ingresos del local', OtherIncome::whereNull('voided_at')->value('description'));
    }

    /** S5 and S7, concurrency: a payment that read the credit as free is refused while it is classified. */
    public function test_a_payment_landing_while_the_credit_is_classified_is_refused(): void
    {
        $due = $this->dueFor($this->member(), $this->year(2026, 1500));
        $credit = $this->movement(1500);
        $refused = false;

        // Models the interleaving: the payment action already checked the credit, then the
        // classification commits between that check and the insert. SQLite is the last word.
        OtherIncome::created(function (OtherIncome $income) use (&$refused, $due, $credit): void {
            if ($refused || $income->bank_movement_id !== $credit->id) {
                return;
            }

            $refused = true;

            $this->assertAborted('payments_bank_movement_other_income', fn () => $this->paymentFor($credit, $due));
        });

        try {
            $this->classify($credit);
        } finally {
            OtherIncome::flushEventListeners();
        }

        $this->assertTrue($refused);
        $this->assertSame(0, Payment::count());
        $this->assertSame(1, OtherIncome::whereNull('voided_at')->count());
        $this->assertSame(0, $due->fresh()->paidCents());
        $this->assertSame('pending', $due->fresh()->status());
    }

    /** S5: the shared payment action turns the classification guard into a validation error. */
    public function test_the_payment_action_refuses_a_classified_credit(): void
    {
        $due = $this->dueFor($this->member(), $this->year(2026, 1500));
        $credit = $this->movement(1500);
        $this->classify($credit);

        try {
            app(RegisterPayment::class)->handle($due, 1500, '2026-02-14', Payment::BANK, null, (string) Str::uuid(), $credit->id);
            $this->fail('El abono clasificado se aceptó como pago de una cuota.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, $due->fresh()->paidCents());
    }

    /** S5: the reconcile screen refuses a classified credit instead of silently charging the due. */
    public function test_a_classified_credit_cannot_be_reconciled_to_dues(): void
    {
        $due = $this->dueFor($this->member(), $this->year(2026, 1500));
        $credit = $this->movement(1500);
        $this->classify($credit);

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$credit->id}/reconcile", [
                'submission_key' => (string) Str::uuid(),
                'allocations' => [$due->id => '15'],
            ])
            ->assertSessionHasErrors('allocations')
            ->assertSessionMissing('status');

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, $due->fresh()->paidCents());
        $this->assertSame('pending', $due->fresh()->status());
    }

    /** S7, preserved: an ordinary split of a free credit still pays its dues. */
    public function test_an_ordinary_split_still_reconciles_a_credit_into_two_dues(): void
    {
        $year = $this->year(2026, 1000);
        $ana = $this->dueFor($this->member(), $year);
        $luis = $this->dueFor($this->member('luis@example.test', 'Luis ficticio'), $year);
        $credit = $this->movement(2000, 'TRANSFERENCIA CONJUNTA');

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$credit->id}/reconcile", [
                'submission_key' => (string) Str::uuid(),
                'allocations' => [$ana->id => '10', $luis->id => '10'],
            ])
            ->assertRedirect('/bank')
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Payment::where('bank_movement_id', $credit->id)->whereNull('voided_at')->count());
        $this->assertSame(1000, $ana->fresh()->paidCents());
        $this->assertSame(1000, $luis->fresh()->paidCents());
        $this->assertSame(0, OtherIncome::count());
    }

    /** S7, preserved: a payment registered by hand, with no movement, still works. */
    public function test_a_manual_payment_without_a_movement_still_works(): void
    {
        $due = $this->dueFor($this->member(), $this->year(2026, 3000));

        $this->actingAs($this->treasury())
            ->post("/dues/{$due->id}/payments", [
                'amount' => '10',
                'paid_on' => '2026-03-01',
                'method' => Payment::CASH,
                'note' => 'Efectivo en la asamblea',
                'submission_key' => (string) Str::uuid(),
            ])
            ->assertRedirect("/fees/{$due->fee_year_id}")
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('payments', [
            'due_id' => $due->id,
            'bank_movement_id' => null,
            'amount_cents' => 1000,
            'method' => Payment::CASH,
        ]);
        $this->assertSame(1000, $due->fresh()->paidCents());
        $this->assertSame('partial', $due->fresh()->status());
    }
}
