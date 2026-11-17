<?php

namespace Tests\Feature;

use App\Modules\Access\Models\User;
use App\Modules\Bank\Models\BankImport;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Fees\Actions\GrantExemption;
use App\Modules\Fees\Actions\SetFeeYear;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\FeeYear;
use App\Modules\Fees\Models\Payment;
use App\Modules\Members\Models\Member;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\AuthTestCase;

class SplitReconcileTest extends AuthTestCase
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

    private function movement(int $cents, string $concept = 'TRANSFERENCIA ANA'): BankMovement
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
            'fingerprint' => hash('sha256', $concept.'|'.$cents),
        ]);
    }

    /**
     * Posts one split; the map is due id => amount in euros as typed in the form.
     *
     * @param  array<int, string>  $allocations
     */
    private function split(int $movementId, array $allocations, ?string $submissionKey = null): TestResponse
    {
        return $this->actingAs($this->treasury())->post("/bank/movements/{$movementId}/reconcile", [
            'submission_key' => $submissionKey ?? (string) Str::uuid(),
            'allocations' => $allocations,
        ]);
    }

    /** Rule 2 and 3: one credit feeds two dues when the amounts add up to it. */
    public function test_one_credit_can_pay_two_dues_when_the_split_adds_up(): void
    {
        $year = $this->year(2026, 1000);
        $ana = $this->dueFor($this->member('ana@example.test', 'Ana ficticia'), $year);
        $luis = $this->dueFor($this->member('luis@example.test', 'Luis ficticio'), $year);
        $movement = $this->movement(2000, 'TRANSFERENCIA CONJUNTA');

        $this->split($movement->id, [$ana->id => '10', $luis->id => '10,00'])
            ->assertRedirect('/bank')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Reparto conciliado desde el extracto.');

        $this->assertDatabaseHas('payments', ['due_id' => $ana->id, 'bank_movement_id' => $movement->id, 'amount_cents' => 1000]);
        $this->assertDatabaseHas('payments', ['due_id' => $luis->id, 'bank_movement_id' => $movement->id, 'amount_cents' => 1000]);
        $this->assertSame('paid', $ana->fresh()->status());
        $this->assertSame('paid', $luis->fresh()->status());
    }

    /** Rule 3: a split that does not add up creates nothing at all. */
    public function test_a_split_that_does_not_add_up_creates_no_payment_at_all(): void
    {
        $year = $this->year(2026, 1000);
        $ana = $this->dueFor($this->member('ana@example.test', 'Ana ficticia'), $year);
        $luis = $this->dueFor($this->member('luis@example.test', 'Luis ficticio'), $year);
        $movement = $this->movement(2000, 'TRANSFERENCIA CONJUNTA');

        foreach ([[$ana->id => '10', $luis->id => '5'], [$ana->id => '10', $luis->id => '15']] as $allocations) {
            $this->split($movement->id, $allocations)
                ->assertSessionHasErrors('allocations');

            $this->assertDatabaseCount('payments', 0);
            $this->assertSame(0, $ana->fresh()->paidCents());
            $this->assertSame(0, $luis->fresh()->paidCents());
        }
    }

    /** Rule 2: an empty split and a negative amount are refused. */
    public function test_a_split_without_positive_amounts_is_refused(): void
    {
        $year = $this->year(2026, 1000);
        $ana = $this->dueFor($this->member('ana@example.test', 'Ana ficticia'), $year);
        $movement = $this->movement(1000);

        $this->split($movement->id, [$ana->id => ''])->assertSessionHasErrors('allocations');
        $this->split($movement->id, [$ana->id => '0'])->assertSessionHasErrors('allocations');
        $this->split($movement->id, [$ana->id => 'diez'])->assertSessionHasErrors('allocations');

        $this->assertDatabaseCount('payments', 0);
    }

    /** Rule 1: a charge never produces a payment, not even inside a split. */
    public function test_a_charge_cannot_be_split(): void
    {
        $year = $this->year(2026, 1000);
        $ana = $this->dueFor($this->member('ana@example.test', 'Ana ficticia'), $year);
        $movement = $this->movement(-2000, 'RECIBO DEVUELTO');

        $this->split($movement->id, [$ana->id => '10'])->assertSessionHasErrors('allocations');

        $this->assertDatabaseCount('payments', 0);
    }

    /** Rule 4: the existing payment guards still decide, and one bad line undoes the whole split. */
    public function test_an_exempt_or_overpaid_due_refuses_the_whole_split(): void
    {
        $year = $this->year(2026, 1000);
        $ana = $this->dueFor($this->member('ana@example.test', 'Ana ficticia'), $year);
        $luis = $this->dueFor($this->member('luis@example.test', 'Luis ficticio'), $year);
        $movement = $this->movement(2000, 'TRANSFERENCIA CONJUNTA');
        app(GrantExemption::class)->handle($ana, 'Ingreso bajo justificado.');

        $this->split($movement->id, [$ana->id => '10', $luis->id => '10'])->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('payments', 0);

        $this->split($movement->id, [$luis->id => '20'])->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('payments', 0);
    }

    /** Rule 6: sending the same split again does not create new payments. */
    public function test_resending_the_same_split_does_not_duplicate_payments(): void
    {
        $year = $this->year(2026, 1000);
        $ana = $this->dueFor($this->member('ana@example.test', 'Ana ficticia'), $year);
        $luis = $this->dueFor($this->member('luis@example.test', 'Luis ficticio'), $year);
        $movement = $this->movement(2000, 'TRANSFERENCIA CONJUNTA');
        $allocations = [$ana->id => '10', $luis->id => '10'];
        $key = (string) Str::uuid();

        $this->split($movement->id, $allocations, $key)->assertSessionHasNoErrors();
        $this->split($movement->id, $allocations, $key)->assertSessionHasNoErrors();

        $this->assertSame(2, Payment::where('bank_movement_id', $movement->id)->count());
        $this->assertSame(2000, Payment::where('bank_movement_id', $movement->id)->whereNull('voided_at')->sum('amount_cents'));
    }

    /** Rule 5 and 7: a movement has a capacity, and voiding one part frees exactly that part. */
    public function test_voiding_one_part_of_the_split_frees_exactly_that_part(): void
    {
        $year = $this->year(2026, 1000);
        $ana = $this->dueFor($this->member('ana@example.test', 'Ana ficticia'), $year);
        $luis = $this->dueFor($this->member('luis@example.test', 'Luis ficticio'), $year);
        $movement = $this->movement(2000, 'TRANSFERENCIA CONJUNTA');

        $this->split($movement->id, [$ana->id => '10', $luis->id => '10'])->assertSessionHasNoErrors();

        $anaPayment = Payment::where('due_id', $ana->id)->firstOrFail();
        $this->actingAs($this->treasury())
            ->post("/bank/payments/{$anaPayment->id}/void", ['reason' => 'Se repartió mal.'])
            ->assertRedirect('/bank')
            ->assertSessionHasNoErrors();

        // Rule 9: nothing is deleted, the voided payment keeps its reason.
        $this->assertDatabaseHas('payments', ['id' => $anaPayment->id, 'void_reason' => 'Se repartió mal.']);
        $this->assertSame('pending', $ana->fresh()->status());

        // The freed part is offered again and can pay another due of the same amount.
        $this->split($movement->id, [$ana->id => '10'])->assertSessionHasNoErrors();
        $this->assertSame('paid', $ana->fresh()->status());
        $this->assertSame(3, Payment::where('bank_movement_id', $movement->id)->count());
    }

    /** Rule 5: the capacity is the movement amount, not a single payment. */
    public function test_a_split_beyond_the_movement_amount_is_refused(): void
    {
        $year = $this->year(2026, 1000);
        $ana = $this->dueFor($this->member('ana@example.test', 'Ana ficticia'), $year);
        $luis = $this->dueFor($this->member('luis@example.test', 'Luis ficticio'), $year);
        $movement = $this->movement(2000, 'TRANSFERENCIA CONJUNTA');

        $this->split($movement->id, [$ana->id => '10'])->assertSessionHasErrors('allocations');
        $this->assertDatabaseCount('payments', 0);

        $this->split($movement->id, [$ana->id => '10', $luis->id => '10'])->assertSessionHasNoErrors();
        $this->split($movement->id, [$ana->id => '5'])->assertSessionHasErrors('allocations');
        $this->assertSame(2, Payment::where('bank_movement_id', $movement->id)->count());
    }

    /** Rule 5, in the database: a direct insert above the capacity is aborted by SQLite. */
    public function test_the_database_refuses_payments_beyond_the_movement_amount(): void
    {
        $year = $this->year(2026, 1000);
        $ana = $this->dueFor($this->member('ana@example.test', 'Ana ficticia'), $year);
        $luis = $this->dueFor($this->member('luis@example.test', 'Luis ficticio'), $year);
        $movement = $this->movement(1500, 'TRANSFERENCIA CONJUNTA');

        Payment::forceCreate([
            'due_id' => $ana->id,
            'bank_movement_id' => $movement->id,
            'amount_cents' => 1000,
            'paid_on' => '2026-02-14',
            'method' => Payment::BANK,
            'submission_key' => (string) Str::uuid(),
        ]);

        $this->expectException(QueryException::class);

        Payment::forceCreate([
            'due_id' => $luis->id,
            'bank_movement_id' => $movement->id,
            'amount_cents' => 1000,
            'paid_on' => '2026-02-14',
            'method' => Payment::BANK,
            'submission_key' => (string) Str::uuid(),
        ]);
    }

    public function test_the_bank_screen_offers_only_what_is_left_of_each_credit(): void
    {
        $year = $this->year(2026, 1000);
        $ana = $this->dueFor($this->member('ana@example.test', 'Ana ficticia'), $year);
        $luis = $this->dueFor($this->member('luis@example.test', 'Luis ficticio'), $year);
        $movement = $this->movement(2000, 'TRANSFERENCIA CONJUNTA');

        $this->split($movement->id, [$ana->id => '10'])->assertSessionHasErrors('allocations');

        $this->actingAs($this->treasury())->get('/bank')
            ->assertOk()
            ->assertSee('Quedan 20,00 € por conciliar');

        $this->split($movement->id, [$ana->id => '10', $luis->id => '10'])->assertSessionHasNoErrors();

        $this->actingAs($this->treasury())->get('/bank')
            ->assertOk()
            ->assertDontSee('Quedan 20,00 € por conciliar')
            ->assertSee('Anular y liberar')
            ->assertSee('10,00 €');
    }
}
