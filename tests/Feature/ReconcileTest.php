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

    private function treasury(): User
    {
        return User::firstOrCreate(
            ['email' => 'tesoreria@example.test'],
            ['name' => 'Persona ficticia', 'role' => 'treasury', 'password' => 'treasury-secret-value'],
        );
    }

    private function board(): User
    {
        return User::firstOrCreate(
            ['email' => 'junta@example.test'],
            ['name' => 'Persona ficticia', 'role' => 'board', 'password' => 'board-secret-value'],
        );
    }

    private function movement(int $cents, string $concept = 'TRANSFERENCIA ANA'): BankMovement
    {
        $importer = User::firstOrCreate(
            ['email' => 'importador@example.test'],
            ['name' => 'Importador ficticio', 'role' => 'treasury', 'password' => 'importer-secret-value'],
        );

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

        $this->post("/bank/movements/{$movement->id}/reconcile", ['due_id' => $due->id])
            ->assertRedirect('/login');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_board_cannot_reconcile_even_though_it_can_see_movements(): void
    {
        $due = $this->dueFor($this->member(), $this->year());
        $movement = $this->movement(3000);

        $this->actingAs($this->board())->get('/bank')->assertOk();
        $this->actingAs($this->board())
            ->post("/bank/movements/{$movement->id}/reconcile", ['due_id' => $due->id])
            ->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_treasury_reconciles_a_credit_movement_and_the_due_becomes_paid(): void
    {
        $due = $this->dueFor($this->member(), $this->year());
        $movement = $this->movement(3000, 'TRANSFERENCIA ANA CUOTA');

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/reconcile", ['due_id' => $due->id])
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

    public function test_resubmitting_the_same_pair_does_not_create_a_second_payment(): void
    {
        $due = $this->dueFor($this->member(), $this->year());
        $movement = $this->movement(1000);

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/reconcile", ['due_id' => $due->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/reconcile", ['due_id' => $due->id])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(1000, $due->fresh()->paidCents());
    }

    public function test_a_reconciled_movement_cannot_jump_to_another_due_while_its_payment_is_active(): void
    {
        $year = $this->year();
        $first = $this->dueFor($this->member('ana@example.test', 'Ana ficticia'), $year);
        $second = $this->dueFor($this->member('luis@example.test', 'Luis ficticio'), $year);
        $movement = $this->movement(1000);

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/reconcile", ['due_id' => $first->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/reconcile", ['due_id' => $second->id])
            ->assertSessionHasErrors('due_id');

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(0, $second->fresh()->paidCents());
    }

    public function test_a_charge_movement_cannot_be_reconciled(): void
    {
        $due = $this->dueFor($this->member(), $this->year());
        $movement = $this->movement(-1500, 'RECIBO DEVUELTO');

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/reconcile", ['due_id' => $due->id])
            ->assertSessionHasErrors('due_id');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_an_exempt_due_cannot_be_reconciled(): void
    {
        $due = $this->dueFor($this->member(), $this->year());
        app(GrantExemption::class)->handle($due, 'Ingreso bajo justificado.');
        $movement = $this->movement(3000);

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/reconcile", ['due_id' => $due->id])
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_payment_larger_than_what_is_pending_is_refused(): void
    {
        $due = $this->dueFor($this->member(), $this->year(2026, 3000));
        $movement = $this->movement(5000);

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/reconcile", ['due_id' => $due->id])
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0, $due->fresh()->paidCents());
    }

    public function test_voiding_the_payment_frees_the_movement_for_a_new_reconciliation(): void
    {
        $due = $this->dueFor($this->member(), $this->year());
        $movement = $this->movement(3000);

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/reconcile", ['due_id' => $due->id])
            ->assertSessionHasNoErrors();

        $payment = Payment::firstOrFail();
        $this->actingAs($this->treasury())
            ->post("/bank/payments/{$payment->id}/void", ['reason' => 'Se concilió con la cuota equivocada.'])
            ->assertRedirect('/bank')
            ->assertSessionHas('status', 'Pago anulado: el movimiento vuelve a estar libre.');

        $this->assertNotNull($payment->fresh()->voided_at);

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/reconcile", ['due_id' => $due->id])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('payments', 2);
        $this->assertSame(1, Payment::whereNull('voided_at')->count());
        $this->assertSame('paid', $due->fresh()->status());
    }

    public function test_the_movements_screen_shows_the_form_only_for_credit_movements_and_the_linked_payment(): void
    {
        $member = $this->member('ana@example.test', 'Ana Alcaraz');
        $due = $this->dueFor($member, $this->year());
        $credit = $this->movement(1000, 'TRANSFERENCIA ANA');
        $charge = $this->movement(-500, 'RECIBO DEVUELTO');

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$credit->id}/reconcile", ['due_id' => $due->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->treasury())->get('/bank')
            ->assertOk()
            ->assertSee('Conciliado con Ana Alcaraz')
            ->assertDontSee("/bank/movements/{$charge->id}/reconcile", false);
    }
}
