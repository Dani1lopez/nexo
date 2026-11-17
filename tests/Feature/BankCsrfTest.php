<?php

namespace Tests\Feature;

use App\Modules\Access\Models\User;
use App\Modules\Bank\Models\BankImport;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Fees\Actions\RegisterPayment;
use App\Modules\Fees\Actions\SetFeeYear;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\Payment;
use App\Modules\Members\Models\Member;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\Support\AuthTestCase;

/**
 * The three bank mutations are the money-facing side of treasury, so each one must be
 * unreachable without a valid request-forgery token (#42).
 */
class BankCsrfTest extends AuthTestCase
{
    private function treasury(): User
    {
        return User::where('email', 'tesoreria@example.test')->first()
            ?? $this->account('tesoreria@example.test', 'treasury', 'treasury-secret-value');
    }

    /**
     * The framework skips request-forgery checks while running the test suite, so the real
     * middleware is kept and only that bypass is lifted. Production behaviour is untouched.
     */
    private function enforceRequestForgeryProtection(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    private function due(int $cents = 3000): Due
    {
        $member = Member::create(['name' => 'Socia ficticia', 'email' => 'socia.csrf@example.test', 'joined_on' => '2024-01-15']);
        $year = app(SetFeeYear::class)->handle(2026, $cents);

        return Due::where('member_id', $member->id)->where('fee_year_id', $year->id)->firstOrFail();
    }

    private function movement(int $cents = 1000, string $concept = 'TRANSFERENCIA FICTICIA'): BankMovement
    {
        $import = BankImport::forceCreate([
            'user_id' => $this->treasury()->id,
            'imported_count' => 1,
            'duplicate_count' => 0,
            'rejected_count' => 0,
        ]);

        return BankMovement::forceCreate([
            'bank_import_id' => $import->id,
            'occurred_on' => '2026-03-01',
            'amount_cents' => $cents,
            'concept' => $concept,
            'fingerprint' => BankMovement::fingerprint('2026-03-01', $cents, $concept),
        ]);
    }

    private function statement(): UploadedFile
    {
        $content = "fecha;importe;concepto\n01/03/2026;10,00;Cuota ficticia\n";

        return UploadedFile::fake()->createWithContent('extracto-ficticio.csv', $content);
    }

    /**
     * Everything a rejected bank request could touch: the rows and the counters kept on the
     * import log. The snapshot is compared as a whole so an extra create and a modified
     * counter both fail the test.
     *
     * @return array<string, int>
     */
    private function bankState(): array
    {
        return [
            'imports' => BankImport::count(),
            'movements' => BankMovement::count(),
            'payments' => Payment::count(),
            'active_payments' => Payment::whereNull('voided_at')->count(),
            'payments_cents' => (int) Payment::sum('amount_cents'),
            'imported_count' => (int) BankImport::sum('imported_count'),
            'duplicate_count' => (int) BankImport::sum('duplicate_count'),
            'rejected_count' => (int) BankImport::sum('rejected_count'),
        ];
    }

    /** S1 + S3: no token and a wrong token are both refused, and the ledger is left untouched. */
    public function test_importing_a_statement_without_or_with_a_wrong_token_is_refused_with_419_and_changes_nothing(): void
    {
        $this->actingAs($this->treasury());
        $this->movement(1000, 'MOVIMIENTO YA IMPORTADO');
        $this->enforceRequestForgeryProtection();
        $before = $this->bankState();

        $this->post('/bank/imports', ['file' => $this->statement()])->assertStatus(419);
        $this->post('/bank/imports', ['file' => $this->statement(), '_token' => 'token-de-prueba-incorrecto'])->assertStatus(419);

        $this->assertSame($before, $this->bankState());
        $this->assertDatabaseMissing('bank_movements', ['concept' => 'Cuota ficticia']);
    }

    /** S2: a valid token is not refused, so the import reaches the operation. */
    public function test_a_statement_import_with_a_valid_token_reaches_the_operation(): void
    {
        $this->actingAs($this->treasury());
        $this->enforceRequestForgeryProtection();
        $this->get('/bank')->assertOk();

        $this->post('/bank/imports', ['file' => $this->statement(), '_token' => session()->token()])
            ->assertRedirect('/bank')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('bank_movements', ['amount_cents' => 1000, 'concept' => 'Cuota ficticia']);
        $this->assertDatabaseHas('bank_imports', ['imported_count' => 1, 'duplicate_count' => 0, 'rejected_count' => 0]);
    }

    /** S1 + S3: reconciling a movement is refused without a token, and no payment appears. */
    public function test_reconciling_without_or_with_a_wrong_token_is_refused_with_419_and_changes_nothing(): void
    {
        $due = $this->due();
        $movement = $this->movement(1000, 'TRANSFERENCIA SOCIA FICTICIA');
        $this->actingAs($this->treasury());
        $this->enforceRequestForgeryProtection();
        $before = $this->bankState();
        $form = ['submission_key' => (string) Str::uuid(), 'allocations' => [$due->id => '10']];

        $this->post("/bank/movements/{$movement->id}/reconcile", $form)->assertStatus(419);
        $this->post("/bank/movements/{$movement->id}/reconcile", [...$form, '_token' => 'token-de-prueba-incorrecto'])->assertStatus(419);

        $this->assertSame($before, $this->bankState());
        $this->assertSame(0, $due->fresh()->paidCents());
        $this->assertSame('pending', $due->fresh()->status());
    }

    /** S2: a valid token is not refused, so the reconciliation reaches the operation. */
    public function test_a_reconciliation_with_a_valid_token_reaches_the_operation(): void
    {
        $due = $this->due();
        $movement = $this->movement(3000, 'TRANSFERENCIA SOCIA FICTICIA');
        $this->actingAs($this->treasury());
        $this->enforceRequestForgeryProtection();
        $this->get('/bank')->assertOk();

        $this->post("/bank/movements/{$movement->id}/reconcile", [
            'submission_key' => (string) Str::uuid(),
            'allocations' => [$due->id => '30'],
            '_token' => session()->token(),
        ])->assertRedirect('/bank')->assertSessionHas('status', 'Pago conciliado desde el extracto.');

        $this->assertDatabaseHas('payments', [
            'due_id' => $due->id,
            'bank_movement_id' => $movement->id,
            'amount_cents' => 3000,
            'voided_at' => null,
        ]);
        $this->assertSame('paid', $due->fresh()->status());
    }

    /** S1 + S3: undoing a reconciliation is refused without a token, and the payment stays alive. */
    public function test_voiding_a_reconciled_payment_without_or_with_a_wrong_token_is_refused_with_419_and_changes_nothing(): void
    {
        $due = $this->due();
        $movement = $this->movement(1000, 'TRANSFERENCIA SOCIA FICTICIA');
        $payment = app(RegisterPayment::class)->handle($due, 1000, '2026-03-01', Payment::BANK, null, 'csrf-void-key', $movement->id);
        $this->actingAs($this->treasury());
        $this->enforceRequestForgeryProtection();
        $before = $this->bankState();

        $this->post("/bank/payments/{$payment->id}/void", ['reason' => 'Motivo ficticio'])->assertStatus(419);
        $this->post("/bank/payments/{$payment->id}/void", ['reason' => 'Motivo ficticio', '_token' => 'token-de-prueba-incorrecto'])->assertStatus(419);

        $this->assertSame($before, $this->bankState());
        $this->assertNull($payment->fresh()->voided_at);
        $this->assertNull($payment->fresh()->void_reason);
        $this->assertSame(1000, $due->fresh()->paidCents());
    }

    /** S2: a valid token is not refused, so the void reaches the operation. */
    public function test_voiding_a_reconciled_payment_with_a_valid_token_reaches_the_operation(): void
    {
        $due = $this->due();
        $movement = $this->movement(1000, 'TRANSFERENCIA SOCIA FICTICIA');
        $payment = app(RegisterPayment::class)->handle($due, 1000, '2026-03-01', Payment::BANK, null, 'csrf-void-key', $movement->id);
        $this->actingAs($this->treasury());
        $this->enforceRequestForgeryProtection();
        $this->get('/bank')->assertOk();

        $this->post("/bank/payments/{$payment->id}/void", [
            'reason' => 'Motivo ficticio',
            '_token' => session()->token(),
        ])->assertRedirect('/bank')->assertSessionHas('status', 'Pago anulado: el movimiento vuelve a estar libre.');

        $this->assertNotNull($payment->fresh()->voided_at);
        $this->assertSame('Motivo ficticio', $payment->fresh()->void_reason);
        $this->assertSame(0, $due->fresh()->paidCents());
    }
}
