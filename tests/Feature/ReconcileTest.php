<?php

namespace Tests\Feature;

use App\Modules\Access\Models\User;
use App\Modules\Bank\Models\BankImport;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Fees\Actions\GrantExemption;
use App\Modules\Fees\Actions\RegisterPayment;
use App\Modules\Fees\Actions\SetFeeYear;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\FeeYear;
use App\Modules\Fees\Models\Payment;
use App\Modules\Members\Models\Member;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\AuthTestCase;

class ReconcileTest extends AuthTestCase
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

    private function provisionUser(string $email, string $role, string $secret, string $name = 'Persona ficticia'): User
    {
        // Role is never mass assigned since #25, so accounts are provisioned explicitly.
        return User::where('email', $email)->first()
            ?? User::provision($name, $email, $secret, $role);
    }

    private function treasury(): User
    {
        return $this->provisionUser('tesoreria@example.test', 'treasury', 'treasury-secret-value');
    }

    private function board(): User
    {
        return $this->provisionUser('junta@example.test', 'board', 'board-secret-value');
    }

    private function movement(int $cents, string $concept = 'TRANSFERENCIA ANA'): BankMovement
    {
        $importer = $this->provisionUser('importador@example.test', 'treasury', 'importer-secret-value', 'Importador ficticio');

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

    public function test_anonymous_visitors_are_sent_to_login(): void
    {
        $due = $this->dueFor($this->member(), $this->year());
        $movement = $this->movement(3000);

        $this->post("/bank/movements/{$movement->id}/reconcile", ['submission_key' => (string) Str::uuid(), 'allocations' => [$due->id => '30']])
            ->assertRedirect('/login');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_board_cannot_reconcile_even_though_it_can_see_movements(): void
    {
        $due = $this->dueFor($this->member(), $this->year());
        $movement = $this->movement(3000);

        $this->actingAs($this->board())->get('/bank')->assertOk();
        $this->actingAs($this->board())
            ->post("/bank/movements/{$movement->id}/reconcile", ['submission_key' => (string) Str::uuid(), 'allocations' => [$due->id => '30']])
            ->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_treasury_reconciles_a_credit_movement_and_the_due_becomes_paid(): void
    {
        $due = $this->dueFor($this->member(), $this->year());
        $movement = $this->movement(3000, 'TRANSFERENCIA ANA CUOTA');

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/reconcile", ['submission_key' => (string) Str::uuid(), 'allocations' => [$due->id => '30']])
            ->assertRedirect('/bank')
            ->assertSessionHas('status', 'Pago conciliado desde el extracto.');

        $this->assertDatabaseHas('payments', [
            'due_id' => $due->id,
            'bank_movement_id' => $movement->id,
            'amount_cents' => 3000,
            'method' => Payment::BANK,
            'voided_at' => null,
        ]);
        $this->assertSame('paid', $due->fresh()->status());
    }

    public function test_resubmitting_the_same_form_does_not_create_a_second_payment(): void
    {
        $due = $this->dueFor($this->member(), $this->year());
        $movement = $this->movement(1000);
        $form = ['submission_key' => (string) Str::uuid(), 'allocations' => [$due->id => '10']];

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/reconcile", $form)
            ->assertSessionHasNoErrors();

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/reconcile", $form)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1000, $due->fresh()->paidCents());
    }

    public function test_a_movement_used_up_to_its_capacity_cannot_pay_another_due(): void
    {
        // The capacity of a movement is its own amount (#34): a 10,00 € credit already spent
        // leaves nothing behind for a second due, and the payment guard says so on the amount.
        $year = $this->year();
        $first = $this->dueFor($this->member('ana@example.test', 'Ana ficticia'), $year);
        $second = $this->dueFor($this->member('luis@example.test', 'Luis ficticio'), $year);
        $movement = $this->movement(1000);
        $register = app(RegisterPayment::class);

        $payment = $register->handle($first, 1000, '2026-02-14', Payment::BANK, null, 'capacity-key-1', $movement->id);

        try {
            $register->handle($second, 1000, '2026-02-14', Payment::BANK, null, 'capacity-key-2', $movement->id);
            $this->fail('El movimiento no tenía importe libre y el pago se aceptó.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->assertTrue($payment->isActive());
        $this->assertSame(1, Payment::where('bank_movement_id', $movement->id)->whereNull('voided_at')->count());
        $this->assertSame(1000, $first->fresh()->paidCents());
        $this->assertSame(0, $second->fresh()->paidCents());
    }

    public function test_a_charge_movement_cannot_be_reconciled(): void
    {
        $due = $this->dueFor($this->member(), $this->year());
        $movement = $this->movement(-1500, 'RECIBO DEVUELTO');

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/reconcile", ['submission_key' => (string) Str::uuid(), 'allocations' => [$due->id => '15']])
            ->assertSessionHasErrors('allocations');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_an_exempt_due_cannot_be_reconciled(): void
    {
        $due = $this->dueFor($this->member(), $this->year());
        app(GrantExemption::class)->handle($due, 'Ingreso bajo justificado.');
        $movement = $this->movement(3000);

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/reconcile", ['submission_key' => (string) Str::uuid(), 'allocations' => [$due->id => '30']])
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_payment_larger_than_what_is_pending_is_refused(): void
    {
        $due = $this->dueFor($this->member(), $this->year(2026, 3000));
        $movement = $this->movement(5000);

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/reconcile", ['submission_key' => (string) Str::uuid(), 'allocations' => [$due->id => '50']])
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0, $due->fresh()->paidCents());
    }

    public function test_voiding_the_payment_frees_the_movement_for_a_new_reconciliation(): void
    {
        $due = $this->dueFor($this->member(), $this->year());
        $movement = $this->movement(3000);

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/reconcile", ['submission_key' => (string) Str::uuid(), 'allocations' => [$due->id => '30']])
            ->assertSessionHasNoErrors();

        $payment = Payment::firstOrFail();
        $this->actingAs($this->treasury())
            ->post("/bank/payments/{$payment->id}/void", ['reason' => 'Se concilió con la cuota equivocada.'])
            ->assertRedirect('/bank')
            ->assertSessionHas('status', 'Pago anulado: el movimiento vuelve a estar libre.');

        $this->assertNotNull($payment->fresh()->voided_at);

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/reconcile", ['submission_key' => (string) Str::uuid(), 'allocations' => [$due->id => '30']])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('payments', 2);
        $this->assertSame(1, Payment::whereNull('voided_at')->count());
        $this->assertSame('paid', $due->fresh()->status());
    }

    /** Spec S2: resending the same form after voiding its payment is refused, never a false success. */
    public function test_resending_the_form_after_voiding_the_payment_is_refused_and_changes_nothing(): void
    {
        $due = $this->dueFor($this->member(), $this->year());
        $movement = $this->movement(3000);
        $form = ['submission_key' => (string) Str::uuid(), 'allocations' => [$due->id => '30']];

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/reconcile", $form)
            ->assertSessionHasNoErrors();

        $payment = Payment::firstOrFail();
        $this->actingAs($this->treasury())
            ->post("/bank/payments/{$payment->id}/void", ['reason' => 'Se concilió con la cuota equivocada.'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/reconcile", $form)
            ->assertSessionHasErrors('submission_key')
            ->assertSessionMissing('status');

        // The refusal is visible on the screen, not only in the session.
        $this->from('/bank')->followingRedirects()
            ->post("/bank/movements/{$movement->id}/reconcile", $form)
            ->assertOk()
            ->assertSee('anulaste un pago de este reparto');

        // Nothing moved: one voided payment and nothing active.
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(0, Payment::whereNull('voided_at')->count());
        $this->assertSame('Se concilió con la cuota equivocada.', $payment->fresh()->void_reason);
        $this->assertSame(0, $due->fresh()->paidCents());
        $this->assertSame('pending', $due->fresh()->status());
    }

    public function test_the_database_holds_the_movement_capacity(): void
    {
        // Models the simultaneous submission the verifier described: raw inserts for the same
        // movement, each with its own submission key, bypassing every check in PHP.
        $year = $this->year(2026, 1000);
        $due = $this->dueFor($this->member('ana@example.test', 'Ana ficticia'), $year);
        $other = $this->dueFor($this->member('luis@example.test', 'Luis ficticio'), $year);
        $movement = $this->movement(1000);

        // A movement can be split: two active payments inside its capacity are allowed.
        foreach ([[$due, 400, 'probe-key-1'], [$other, 600, 'probe-key-2']] as [$target, $cents, $key]) {
            Payment::forceCreate([
                'due_id' => $target->id,
                'bank_movement_id' => $movement->id,
                'amount_cents' => $cents,
                'paid_on' => '2026-02-14',
                'method' => Payment::BANK,
                'submission_key' => $key,
            ]);
        }

        $this->assertSame(2, Payment::where('bank_movement_id', $movement->id)->whereNull('voided_at')->count());
        $this->assertSame(1000, (int) Payment::where('bank_movement_id', $movement->id)->whereNull('voided_at')->sum('amount_cents'));

        $this->expectException(QueryException::class);

        // One more active payment would take the movement above its own amount.
        Payment::forceCreate([
            'due_id' => $due->id,
            'bank_movement_id' => $movement->id,
            'amount_cents' => 100,
            'paid_on' => '2026-02-14',
            'method' => Payment::BANK,
            'submission_key' => 'probe-key-3',
        ]);
    }

    public function test_the_bank_void_refuses_a_payment_that_did_not_come_from_a_movement(): void
    {
        $due = $this->dueFor($this->member(), $this->year());
        $manual = Payment::forceCreate([
            'due_id' => $due->id,
            'amount_cents' => 500,
            'paid_on' => '2026-02-01',
            'method' => Payment::CASH,
            'submission_key' => 'manual-key',
        ]);

        $this->actingAs($this->treasury())
            ->post("/bank/payments/{$manual->id}/void", ['reason' => 'No procede.'])
            ->assertSessionHasErrors('payment');

        $this->assertNull($manual->fresh()->voided_at);
    }

    public function test_the_movements_screen_shows_the_form_only_for_credit_movements_and_the_linked_payment(): void
    {
        $member = $this->member('ana@example.test', 'Ana Alcaraz');
        $due = $this->dueFor($member, $this->year());
        $credit = $this->movement(1000, 'TRANSFERENCIA ANA');
        $charge = $this->movement(-500, 'RECIBO DEVUELTO');

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$credit->id}/reconcile", ['submission_key' => (string) Str::uuid(), 'allocations' => [$due->id => '10']])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->treasury())->get('/bank')
            ->assertOk()
            ->assertSee('Conciliado con Ana Alcaraz')
            ->assertDontSee("/bank/movements/{$charge->id}/reconcile", false);
    }
}
