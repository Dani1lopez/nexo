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
use Illuminate\Support\Facades\DB;
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
     * Posts one split; the map is due id => amount in euros as typed in the form. An untouched
     * row arrives as null once Laravel turns the empty string into null.
     *
     * @param  array<int, string|null>  $allocations
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

    /**
     * Rule 2: the screen renders one row per pending due, so an untouched row arrives empty.
     * An empty amount is a due that does not take part, never a format error.
     */
    public function test_an_empty_amount_for_an_untouched_due_does_not_take_part(): void
    {
        // The reported reproduction: a 20,00 € credit, a due of 20,00 € and a 5,00 € due left untouched.
        $large = $this->year(2025, 2000);
        $small = $this->year(2026, 500);
        $ana = $this->dueFor($this->member('ana@example.test', 'Ana ficticia'), $large);
        $luis = $this->dueFor($this->member('luis@example.test', 'Luis ficticio'), $small);
        $movement = $this->movement(2000, 'TRANSFERENCIA CONJUNTA');

        $this->split($movement->id, [$ana->id => '20,00', $luis->id => ''])
            ->assertRedirect('/bank')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Pago conciliado desde el extracto.');

        $this->assertDatabaseHas('payments', [
            'due_id' => $ana->id,
            'bank_movement_id' => $movement->id,
            'amount_cents' => 2000,
        ]);
        $this->assertSame(1, Payment::where('bank_movement_id', $movement->id)->count());
        $this->assertSame(0, $luis->fresh()->paidCents());
        $this->assertSame('paid', $ana->fresh()->status());
    }

    /** Rule 2: an amount that arrives as null is the same untouched row, not a format error. */
    public function test_a_null_amount_for_an_untouched_due_does_not_take_part(): void
    {
        $large = $this->year(2025, 2000);
        $small = $this->year(2026, 500);
        $ana = $this->dueFor($this->member('ana@example.test', 'Ana ficticia'), $large);
        $luis = $this->dueFor($this->member('luis@example.test', 'Luis ficticio'), $small);
        $movement = $this->movement(2000, 'TRANSFERENCIA CONJUNTA');

        $this->split($movement->id, [$ana->id => '20,00', $luis->id => null])
            ->assertRedirect('/bank')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(0, $luis->fresh()->paidCents());
    }

    /** Rule 2: an empty amount never rescues a typed amount that cannot be parsed. */
    public function test_an_empty_amount_does_not_hide_a_value_that_cannot_be_parsed(): void
    {
        $large = $this->year(2025, 2000);
        $small = $this->year(2026, 500);
        $ana = $this->dueFor($this->member('ana@example.test', 'Ana ficticia'), $large);
        $luis = $this->dueFor($this->member('luis@example.test', 'Luis ficticio'), $small);
        $movement = $this->movement(2000, 'TRANSFERENCIA CONJUNTA');

        $this->split($movement->id, [$ana->id => 'diez', $luis->id => ''])->assertSessionHasErrors('allocations');
        $this->split($movement->id, [$ana->id => '0', $luis->id => ''])->assertSessionHasErrors('allocations');

        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * Rule 5 and 3: a line for a due this very movement already pays is refused on the split,
     * because the existing payment guard would otherwise drop it and keep the money unassigned.
     */
    public function test_a_line_for_a_due_already_paid_by_this_movement_is_refused(): void
    {
        $year = $this->year(2026, 10000);
        $ana = $this->dueFor($this->member('ana@example.test', 'Ana ficticia'), $year);
        $luis = $this->dueFor($this->member('luis@example.test', 'Luis ficticio'), $year);
        $carmen = $this->dueFor($this->member('carmen@example.test', 'Carmen ficticia'), $year);
        $movement = $this->movement(10000, 'TRANSFERENCIA CONJUNTA');

        $this->split($movement->id, [$ana->id => '30', $luis->id => '70'])->assertSessionHasNoErrors();

        $luisPayment = Payment::where('due_id', $luis->id)->firstOrFail();
        $this->actingAs($this->treasury())
            ->post("/bank/payments/{$luisPayment->id}/void", ['reason' => 'Se repartió mal.'])
            ->assertSessionHasNoErrors();

        // 70,00 € are free again, so this split adds up but reuses a due the movement already pays.
        $this->split($movement->id, [$ana->id => '20', $carmen->id => '50'])
            ->assertSessionHasErrors('allocations');

        $this->assertSame(3000, (int) Payment::where('due_id', $ana->id)->whereNull('voided_at')->sum('amount_cents'));
        $this->assertSame(0, Payment::where('due_id', $carmen->id)->count());
        $this->assertSame(3000, (int) Payment::where('bank_movement_id', $movement->id)->whereNull('voided_at')->sum('amount_cents'));
        $this->assertSame(0, $carmen->fresh()->paidCents());
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

    /** Spec S2: resending a split whose part was voided is refused and leaves amounts and history untouched. */
    public function test_resending_the_split_after_voiding_one_part_is_refused_and_changes_nothing(): void
    {
        $year = $this->year(2026, 1000);
        $ana = $this->dueFor($this->member('ana@example.test', 'Ana ficticia'), $year);
        $luis = $this->dueFor($this->member('luis@example.test', 'Luis ficticio'), $year);
        $movement = $this->movement(2000, 'TRANSFERENCIA CONJUNTA');
        $allocations = [$ana->id => '10', $luis->id => '10'];
        $key = (string) Str::uuid();

        $this->split($movement->id, $allocations, $key)->assertSessionHasNoErrors();

        $anaPayment = Payment::where('due_id', $ana->id)->firstOrFail();
        $this->actingAs($this->treasury())
            ->post("/bank/payments/{$anaPayment->id}/void", ['reason' => 'Se repartió mal.'])
            ->assertSessionHasNoErrors();

        $this->split($movement->id, $allocations, $key)
            ->assertSessionHasErrors('submission_key')
            ->assertSessionMissing('status');

        // The refusal is visible on the screen, not only in the session.
        $this->from('/bank')->followingRedirects()
            ->post("/bank/movements/{$movement->id}/reconcile", ['submission_key' => $key, 'allocations' => $allocations])
            ->assertOk()
            ->assertSee('anulaste un pago de este reparto');

        // The voided part stays voided and the active part keeps its amount: nothing was replayed.
        $this->assertSame(2, Payment::where('bank_movement_id', $movement->id)->count());
        $this->assertSame(1000, (int) Payment::where('bank_movement_id', $movement->id)->whereNull('voided_at')->sum('amount_cents'));
        $this->assertSame('Se repartió mal.', $anaPayment->fresh()->void_reason);
        $this->assertSame(0, $ana->fresh()->paidCents());
        $this->assertSame('pending', $ana->fresh()->status());
        $this->assertSame(1000, $luis->fresh()->paidCents());
        $this->assertSame('paid', $luis->fresh()->status());
    }

    /** Spec S2 and S3: a fully voided split is also refused, and a fresh form reconciles again. */
    public function test_a_fresh_form_after_voiding_the_whole_split_reconciles_again(): void
    {
        $year = $this->year(2026, 1000);
        $ana = $this->dueFor($this->member('ana@example.test', 'Ana ficticia'), $year);
        $luis = $this->dueFor($this->member('luis@example.test', 'Luis ficticio'), $year);
        $movement = $this->movement(2000, 'TRANSFERENCIA CONJUNTA');
        $allocations = [$ana->id => '10', $luis->id => '10'];
        $key = (string) Str::uuid();

        $this->split($movement->id, $allocations, $key)->assertSessionHasNoErrors();

        foreach (Payment::where('bank_movement_id', $movement->id)->get() as $payment) {
            $this->actingAs($this->treasury())
                ->post("/bank/payments/{$payment->id}/void", ['reason' => 'Se repartió mal.'])
                ->assertSessionHasNoErrors();
        }

        $this->split($movement->id, $allocations, $key)
            ->assertSessionHasErrors('submission_key')
            ->assertSessionMissing('status');

        $this->assertSame(0, (int) Payment::where('bank_movement_id', $movement->id)->whereNull('voided_at')->sum('amount_cents'));

        $this->split($movement->id, $allocations)
            ->assertRedirect('/bank')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Reparto conciliado desde el extracto.');

        $this->assertSame(4, Payment::where('bank_movement_id', $movement->id)->count());
        $this->assertSame(2000, (int) Payment::where('bank_movement_id', $movement->id)->whereNull('voided_at')->sum('amount_cents'));
        $this->assertSame('paid', $ana->fresh()->status());
        $this->assertSame('paid', $luis->fresh()->status());
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

    /**
     * Rule 3 under a concurrent void: the split must verify the end state, not just the read it made
     * before writing. The void is simulated with a model event, so the test is deterministic.
     */
    public function test_a_void_that_lands_mid_split_rolls_the_whole_split_back(): void
    {
        $year = $this->year(2026, 1000);
        $ana = $this->dueFor($this->member('ana@example.test', 'Ana ficticia'), $year);
        $luis = $this->dueFor($this->member('luis@example.test', 'Luis ficticio'), $year);
        $movement = $this->movement(2000, 'TRANSFERENCIA CONJUNTA');

        $released = false;
        Payment::created(function (Payment $payment) use (&$released, $movement): void {
            if ($released || $payment->bank_movement_id !== $movement->id) {
                return;
            }

            // A concurrent void that lands between the equality check and the inserts.
            $released = true;
            Payment::withoutEvents(fn () => $payment->forceFill([
                'voided_at' => now(),
                'void_reason' => 'Anulación simultánea',
            ])->save());
        });

        $this->split($movement->id, [$ana->id => '10', $luis->id => '10'])
            ->assertSessionHasErrors('allocations');

        $this->assertTrue($released);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0, $ana->fresh()->paidCents());
        $this->assertSame(0, $luis->fresh()->paidCents());
    }

    /** Rule 5, in the database: un-voiding a payment cannot push the movement above its capacity. */
    public function test_the_database_refuses_un_voiding_a_payment_beyond_the_movement_amount(): void
    {
        $year = $this->year(2026, 1000);
        $ana = $this->dueFor($this->member('ana@example.test', 'Ana ficticia'), $year);
        $luis = $this->dueFor($this->member('luis@example.test', 'Luis ficticio'), $year);
        $carmen = $this->dueFor($this->member('carmen@example.test', 'Carmen ficticia'), $year);
        $movement = $this->movement(1000, 'TRANSFERENCIA CONJUNTA');

        foreach ([[$ana, 600, null], [$luis, 400, null], [$carmen, 600, '2026-02-15 10:00:00']] as [$due, $cents, $voidedAt]) {
            Payment::forceCreate([
                'due_id' => $due->id,
                'bank_movement_id' => $movement->id,
                'amount_cents' => $cents,
                'paid_on' => '2026-02-14',
                'method' => Payment::BANK,
                'submission_key' => (string) Str::uuid(),
                'voided_at' => $voidedAt,
            ]);
        }

        $voided = Payment::where('due_id', $carmen->id)->firstOrFail();
        $this->assertSame(1000, (int) Payment::where('bank_movement_id', $movement->id)->whereNull('voided_at')->sum('amount_cents'));

        $this->expectException(QueryException::class);

        DB::table('payments')->where('id', $voided->id)->update(['voided_at' => null]);
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
