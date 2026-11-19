<?php

namespace Tests\Feature;

use App\Modules\Access\Models\User;
use App\Modules\Bank\Actions\ReconcileMovement;
use App\Modules\Bank\Models\BankImport;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Fees\Actions\GrantExemption;
use App\Modules\Fees\Actions\RegisterPayment;
use App\Modules\Fees\Actions\SetFeeYear;
use App\Modules\Fees\Actions\VoidPayment;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\FeeYear;
use App\Modules\Fees\Models\Payment;
use App\Modules\Members\Models\Member;
use Illuminate\Support\Str;
use Tests\Support\AuthTestCase;

class SummaryTest extends AuthTestCase
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

    private function movement(int $cents, string $concept, string $date = '2026-03-01'): BankMovement
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
            'occurred_on' => $date,
            'amount_cents' => $cents,
            'concept' => $concept,
            'fingerprint' => BankMovement::fingerprint($date, $cents, $concept),
        ]);
    }

    /** Rule 1: the balance is the signed sum of the imported statement, charges subtracted. */
    public function test_the_balance_adds_credits_and_subtracts_charges(): void
    {
        $this->movement(10000, 'CUOTA ANA');
        $this->movement(5000, 'CUOTA LUIS');
        $this->movement(-3000, 'ALQUILER LOCAL');

        $this->actingAs($this->board())->get('/saldos')
            ->assertOk()
            ->assertSee('Saldo en el banco')
            ->assertSee('120,00 €')
            ->assertSee('El saldo es el del extracto importado')
            ->assertSee('Los pagos en efectivo no cambian este saldo');
    }

    /** Rule 2: the debt is broken down by fee year, newest first, with a link to each year. */
    public function test_the_debt_is_shown_per_fee_year_with_a_link_to_each_year(): void
    {
        $this->member('ana@example.test');
        $this->member('luis@example.test', 'Luis ficticio');
        $older = $this->year(2025, 1000);
        $latest = $this->year(2026, 3000);
        app(RegisterPayment::class)->handle($this->dueFor(Member::where('email', 'ana@example.test')->firstOrFail(), $latest), 1000, '2026-01-10', 'bank', null, 'key-summary');

        $this->actingAs($this->board())->get('/saldos')
            ->assertOk()
            ->assertSeeInOrder(['Cuota de 2026', 'Cuota de 2025'])
            ->assertSee('/fees/'.$latest->id, false)
            ->assertSee('/fees/'.$older->id, false)
            ->assertSeeInOrder(['Cuota de 2026', '60,00 €', '10,00 €', '50,00 €', '2 cuotas pendientes'])
            ->assertSeeInOrder(['Cuota de 2025', '20,00 €', '0,00 €', '20,00 €', '2 cuotas pendientes'])
            ->assertSeeInOrder(['Total pendiente', '70,00 €', '4 cuotas pendientes']);
    }

    /** Rule 3: an exempt due is not owed and a voided payment is not collected. */
    public function test_an_exempt_due_is_not_owed_and_a_voided_payment_is_not_collected(): void
    {
        $ana = $this->member('ana@example.test');
        $luis = $this->member('luis@example.test', 'Luis ficticio');
        $year = $this->year(2026, 3000);
        app(GrantExemption::class)->handle($this->dueFor($ana, $year), 'Situación económica');
        $payment = app(RegisterPayment::class)->handle($this->dueFor($luis, $year), 3000, '2026-01-10', 'cash', null, 'key-voided');
        app(VoidPayment::class)->handle($payment, 'Error de importe');

        $this->actingAs($this->board())->get('/saldos')
            ->assertOk()
            ->assertSeeInOrder(['Cuota de 2026', '30,00 €', '0,00 €', '30,00 €', '1 cuota pendiente'])
            ->assertSeeInOrder(['Total pendiente', '30,00 €']);
    }

    /** Rule 4: a credit without a live payment is unreconciled; a charge is never reconciled. */
    public function test_only_credits_without_a_live_payment_count_as_unreconciled(): void
    {
        $ana = $this->member('ana@example.test');
        $year = $this->year(2026, 3000);
        $reconciled = $this->movement(3000, 'TRANSFERENCIA ANA');
        $this->movement(4000, 'INGRESO SUELTO');
        $this->movement(-2500, 'ALQUILER LOCAL');
        app(ReconcileMovement::class)->handle($reconciled, [$this->dueFor($ana, $year)->id => 3000], (string) Str::uuid());

        $this->actingAs($this->board())->get('/saldos')
            ->assertOk()
            ->assertSee('45,00 €')
            ->assertSee('Sin conciliar')
            ->assertSee('1 abono sin conciliar')
            ->assertSee('40,00 €')
            ->assertSeeInOrder(['Movimientos', '3', 'movimientos en el extracto importado']);
    }

    /** Rule 5: voiding the payment frees the credit again, without deleting anything. */
    public function test_voiding_the_payment_returns_the_credit_to_the_unreconciled_list(): void
    {
        $ana = $this->member('ana@example.test');
        $year = $this->year(2026, 3000);
        $movement = $this->movement(3000, 'TRANSFERENCIA ANA');
        $payment = app(ReconcileMovement::class)->handle($movement, [$this->dueFor($ana, $year)->id => 3000], (string) Str::uuid())[0];

        $this->actingAs($this->treasury())->get('/saldos')
            ->assertSee('0 abonos sin conciliar')
            ->assertSee('0,00 €');

        app(VoidPayment::class)->handle($payment, 'Se anula para volver a conciliar');

        $this->actingAs($this->treasury())->get('/saldos')
            ->assertSee('1 abono sin conciliar')
            ->assertSee('30,00 €')
            ->assertSee('1 cuota pendiente');
    }

    /** Rule 6: the screen is read-only for both roles and closed to anonymous visitors. */
    public function test_the_screen_is_readable_for_both_roles_and_read_only(): void
    {
        $this->get('/saldos')->assertRedirect('/login');
        $this->actingAs($this->board())->get('/saldos')->assertOk()->assertSee('Saldos');
        $this->actingAs($this->treasury())->get('/saldos')->assertOk()->assertSee('Saldos');
        $this->actingAs($this->treasury())->post('/saldos')->assertStatus(405);
    }

    /** Rule 7: the dashboard no longer claims that economic operations are unavailable. */
    public function test_the_dashboard_no_longer_claims_the_operations_are_unavailable(): void
    {
        $this->actingAs($this->board())->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Las operaciones económicas todavía no están disponibles')
            ->assertSee('/saldos', false);
    }

    public function test_the_screen_says_so_when_there_is_nothing_to_summarise(): void
    {
        $this->actingAs($this->board())->get('/saldos')
            ->assertOk()
            ->assertSee('Todavía no se ha importado ningún extracto')
            ->assertSee('Todavía no se ha fijado ninguna cuota')
            ->assertSeeInOrder(['Saldo en caja', '0,00 €', '0 pagos de cuotas en efectivo']);
    }

    public function test_payments_reference_the_movement_so_the_credit_is_not_offered_twice(): void
    {
        $ana = $this->member('ana@example.test');
        $year = $this->year(2026, 3000);
        $movement = $this->movement(3000, 'TRANSFERENCIA ANA');
        app(ReconcileMovement::class)->handle($movement, [$this->dueFor($ana, $year)->id => 3000], (string) Str::uuid());

        $this->assertSame(1, Payment::where('bank_movement_id', $movement->id)->count());
        $this->actingAs($this->board())->get('/saldos')->assertSee('0 abonos sin conciliar');
    }

    /**
     * Preserve (#44): with no classification the summary keeps the same figures and offers no
     * other-income card; the new total only appears when something was classified.
     */
    public function test_the_summary_keeps_its_figures_when_nothing_is_classified(): void
    {
        $ana = $this->member('ana@example.test');
        $year = $this->year(2026, 3000);
        $movement = $this->movement(3000, 'TRANSFERENCIA ANA');
        app(ReconcileMovement::class)->handle($movement, [$this->dueFor($ana, $year)->id => 3000], (string) Str::uuid());

        $this->actingAs($this->board())->get('/saldos')
            ->assertOk()
            ->assertSeeInOrder(['Saldo en el banco', '30,00 €'])
            ->assertSeeInOrder(['Sin conciliar', '0,00 €', '0 abonos sin conciliar'])
            ->assertDontSee('Otros ingresos')
            ->assertSeeInOrder(['Total pendiente', '0,00 €', '0 cuotas pendientes']);
    }

    /** Rule 8 of issue #34: a credit reconciled in part counts only what is still left. */
    public function test_a_partially_reconciled_credit_counts_only_what_is_left(): void
    {
        $ana = $this->member('ana@example.test');
        $luis = $this->member('luis@example.test', 'Luis ficticio');
        $year = $this->year(2026, 1000);
        $movement = $this->movement(2000, 'TRANSFERENCIA CONJUNTA');
        app(ReconcileMovement::class)->handle($movement, [
            $this->dueFor($ana, $year)->id => 1000,
            $this->dueFor($luis, $year)->id => 1000,
        ], (string) Str::uuid());
        app(VoidPayment::class)->handle(Payment::where('due_id', $this->dueFor($ana, $year)->id)->firstOrFail(), 'Se anula una parte.');

        $this->actingAs($this->board())->get('/saldos')
            ->assertOk()
            ->assertSeeInOrder(['Sin conciliar', '10,00 €', '1 abono sin conciliar']);
    }

    /**
     * Rule 8 of issue #62 (S1, S4): the cash balance is the sum of the active cash fee payments.
     * A bank payment neither adds to the cash nor moves the statement balance, so both figures are
     * shown apart.
     */
    public function test_the_cash_balance_sums_the_active_cash_payments_and_leaves_the_bank_alone(): void
    {
        $ana = $this->member('ana@example.test');
        $luis = $this->member('luis@example.test', 'Luis ficticio');
        $year = $this->year(2026, 3000);
        $this->movement(10000, 'CUOTA ANA');
        app(RegisterPayment::class)->handle($this->dueFor($ana, $year), 2000, '2026-02-01', 'cash', null, 'key-cash-ana');
        app(RegisterPayment::class)->handle($this->dueFor($luis, $year), 1000, '2026-02-02', 'cash', null, 'key-cash-luis');
        app(RegisterPayment::class)->handle($this->dueFor($ana, $year), 1000, '2026-02-03', 'bank', null, 'key-bank-ana');

        $this->actingAs($this->board())->get('/saldos')
            ->assertOk()
            ->assertSeeInOrder(['Saldo en el banco', '100,00 €'])
            ->assertSeeInOrder(['Saldo en caja', '30,00 €', '2 pagos de cuotas en efectivo']);
    }

    /**
     * Rule 9 (S4): the cash figure does not wait for a bank import. Without a statement the bank is
     * still the empty state, but the cash already shows the money in the safe.
     */
    public function test_the_cash_balance_is_shown_even_without_a_bank_import(): void
    {
        $ana = $this->member('ana@example.test');
        $year = $this->year(2026, 3000);
        app(RegisterPayment::class)->handle($this->dueFor($ana, $year), 2500, '2026-02-01', 'cash', null, 'key-cash-only');

        $this->actingAs($this->board())->get('/saldos')
            ->assertOk()
            ->assertSee('Todavía no se ha importado ningún extracto')
            ->assertSeeInOrder(['Saldo en caja', '25,00 €', '1 pago de cuota en efectivo'])
            ->assertDontSee('Saldo en el banco');
    }

    /**
     * Rule 10 (S1): a voided cash payment keeps its history but stops holding money, exactly like a
     * voided payment stops counting as collected.
     */
    public function test_a_voided_cash_payment_stops_counting_in_the_cash_balance(): void
    {
        $ana = $this->member('ana@example.test');
        $year = $this->year(2026, 3000);
        $payment = app(RegisterPayment::class)->handle($this->dueFor($ana, $year), 3000, '2026-02-01', 'cash', null, 'key-cash-void');

        $this->actingAs($this->board())->get('/saldos')
            ->assertOk()
            ->assertSeeInOrder(['Saldo en caja', '30,00 €', '1 pago de cuota en efectivo']);

        app(VoidPayment::class)->handle($payment, 'Error de importe');

        $this->actingAs($this->board())->get('/saldos')
            ->assertOk()
            ->assertSeeInOrder(['Saldo en caja', '0,00 €', '0 pagos de cuotas en efectivo']);
    }

    /**
     * Preserve (#44, #62): the cash section is read-only for the board too. It adds no form and the
     * route still rejects writes, so consulting the cash never registers money.
     */
    public function test_the_cash_figure_is_read_only_for_the_board(): void
    {
        $ana = $this->member('ana@example.test');
        $year = $this->year(2026, 3000);
        app(RegisterPayment::class)->handle($this->dueFor($ana, $year), 1000, '2026-02-01', 'cash', null, 'key-cash-board');

        $html = $this->actingAs($this->board())->get('/saldos')
            ->assertOk()
            ->assertSeeInOrder(['Saldo en caja', '10,00 €'])
            ->getContent();

        preg_match('/aria-label="Caja".*?<\/section>/s', $html, $section);

        $this->assertNotEmpty($section);
        $this->assertStringNotContainsString('<form', $section[0]);
        $this->actingAs($this->board())->post('/saldos', ['amount' => '10'])->assertStatus(405);
    }
}
