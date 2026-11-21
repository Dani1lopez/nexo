<?php

namespace Tests\Feature;

use App\Modules\Access\Models\User;
use App\Modules\Bank\Actions\ClassifyBankExpense;
use App\Modules\Bank\Models\BankExpense;
use App\Modules\Bank\Models\BankImport;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Cash\Actions\BuildCashBalance;
use App\Modules\Cash\Actions\MarkCashTransfer;
use App\Modules\Cash\Models\CashTransfer;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\AuthTestCase;

/**
 * Issue #67: a complete statement movement can be marked as a transfer between the bank and the
 * safe. It is never an income or an expense: the bank already moved with the statement and only the
 * cash figure follows the direction.
 */
class CashTransferTest extends AuthTestCase
{
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

    private function movement(int $cents, string $concept = 'RETIRADA EFECTIVO', string $date = '2026-03-01'): BankMovement
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

    public function test_description_is_trimmed_without_coercing_non_string_input(): void
    {
        $movement = $this->movement(-8000, 'Traslado de prueba');
        $route = "/bank/movements/{$movement->id}/transfers";
        $description = str_repeat('á', 200);
        $data = [
            'submission_key' => (string) Str::uuid(),
            'direction' => 'to_cash',
        ];

        $this->actingAs($this->treasury());
        foreach ([123, ['texto'], '   ', $description.'á'] as $invalid) {
            $this->postJson($route, $data + ['description' => $invalid])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('description');
        }
        $this->assertSame(0, CashTransfer::query()->count());

        $this->post($route, $data + ['description' => ' '.$description.' '])
            ->assertRedirect('/bank')
            ->assertSessionHasNoErrors();

        $this->assertSame($description, CashTransfer::query()->sole()->description);
    }

    /** Rule 1: a withdrawal funds the safe without moving the bank balance. */
    public function test_a_withdrawal_funds_the_safe_and_the_bank_stays(): void
    {
        $withdrawal = $this->movement(-8000);

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$withdrawal->id}/transfers", [
                'submission_key' => (string) Str::uuid(),
                'direction' => 'to_cash',
                'description' => 'Retirada para la caja',
            ])
            ->assertRedirect('/bank');

        $transfer = CashTransfer::query()->firstOrFail();
        $this->assertSame('to_cash', $transfer->direction);
        $this->assertSame(8000, $transfer->amount_cents);

        $this->actingAs($this->board())->get('/saldos')
            ->assertOk()
            ->assertSeeInOrder(['Saldo en caja', '80,00 €'])
            ->assertSeeInOrder(['Saldo en el banco', '-80,00 €']);
    }

    /** Rule 2: a deposit takes cash to the bank and reduces it. */
    public function test_a_deposit_takes_cash_to_the_bank(): void
    {
        $this->actingAs($this->treasury())->post('/caja/saldo-inicial', ['amount' => '100,00']);
        $deposit = $this->movement(3000, 'INGRESO EFECTIVO');

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$deposit->id}/transfers", [
                'submission_key' => (string) Str::uuid(),
                'direction' => 'to_bank',
                'description' => 'Ingreso del sobrante',
            ])
            ->assertRedirect('/bank');

        $this->assertSame(7000, app(BuildCashBalance::class)->handle()['cashCents']);
    }

    /** Rule 3: the direction must match the statement. */
    public function test_the_direction_must_match_the_sign_of_the_movement(): void
    {
        $withdrawal = $this->movement(-8000);

        try {
            app(MarkCashTransfer::class)->handle($withdrawal, 'to_bank', 'Al revés', (string) Str::uuid(), (int) $this->treasury()->id);
            $this->fail('A withdrawal marked as a deposit should have been refused.');
        } catch (ValidationException $e) {
            $this->assertSame('Un cargo solo puede ser dinero que entra en la caja.', $e->errors()['direction'][0]);
        }

        $this->assertSame(0, CashTransfer::query()->count());
    }

    /** Rule 4: one live transfer per movement, and a changed replay is refused. */
    public function test_one_live_transfer_per_movement_and_a_changed_replay_is_refused(): void
    {
        $withdrawal = $this->movement(-8000);
        $key = (string) Str::uuid();

        $first = app(MarkCashTransfer::class)->handle($withdrawal, 'to_cash', 'Retirada', $key, (int) $this->treasury()->id);
        $again = app(MarkCashTransfer::class)->handle($withdrawal, 'to_cash', 'Retirada', $key, (int) $this->treasury()->id);

        $this->assertTrue($first->is($again));
        $this->assertSame(1, CashTransfer::query()->count());

        try {
            app(MarkCashTransfer::class)->handle($withdrawal, 'to_cash', 'Otra cosa', $key, (int) $this->treasury()->id);
            $this->fail('A reused form with other values should have been refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ya se usó con otra dirección', $e->errors()['submission_key'][0]);
        }

        try {
            app(MarkCashTransfer::class)->handle($withdrawal, 'to_cash', 'Segundo', (string) Str::uuid(), (int) $this->treasury()->id);
            $this->fail('A second live transfer should have been refused.');
        } catch (ValidationException $e) {
            $this->assertSame('El movimiento ya está marcado como traspaso.', $e->errors()['movement'][0]);
        }

        $this->assertSame(1, CashTransfer::query()->count());
    }

    /** Rule 5: a movement with another destination cannot become a transfer. */
    public function test_a_movement_with_another_destination_is_refused(): void
    {
        $charge = $this->movement(-8000, 'COMPRA MATERIAL');
        app(ClassifyBankExpense::class)->handle($charge, 'material', 'Material', (string) Str::uuid(), (int) $this->treasury()->id);

        try {
            app(MarkCashTransfer::class)->handle($charge, 'to_cash', 'Retirada', (string) Str::uuid(), (int) $this->treasury()->id);
            $this->fail('A classified charge should not become a transfer.');
        } catch (ValidationException $e) {
            $this->assertSame('El movimiento está clasificado como gasto: anula ese gasto antes de marcarlo como traspaso.', $e->errors()['movement'][0]);
        }

        $this->assertSame(1, BankExpense::query()->count());
    }

    /** Rule 5b: a movement already marked as a transfer refuses every other destination. */
    public function test_a_transfer_blocks_another_destination_written_afterwards(): void
    {
        $charge = $this->movement(-8000, 'COMPRA MATERIAL');
        app(MarkCashTransfer::class)->handle($charge, 'to_cash', 'Retirada', (string) Str::uuid(), (int) $this->treasury()->id);

        try {
            app(ClassifyBankExpense::class)->handle($charge, 'material', 'Material', (string) Str::uuid(), (int) $this->treasury()->id);
            $this->fail('A charge marked as a transfer should not become an expense.');
        } catch (ValidationException $e) {
            $this->assertSame('El movimiento ya está marcado como traspaso: anúlalo antes de clasificarlo como gasto.', $e->errors()['movement'][0]);
        }

        $this->assertSame(0, BankExpense::query()->count());
        $this->assertSame(1, CashTransfer::query()->count());
    }

    /** Rule 6: voiding keeps the history, undoes the cash effect and frees the movement. */
    public function test_voiding_undoes_the_cash_effect_and_frees_the_movement(): void
    {
        $withdrawal = $this->movement(-8000);

        $route = "/bank/movements/{$withdrawal->id}/transfers";
        $data = [
            'submission_key' => (string) Str::uuid(),
            'direction' => 'to_cash',
            'description' => 'Retirada para la caja',
        ];

        $this->actingAs($this->treasury())->post($route, $data)
            ->assertRedirect('/bank')
            ->assertSessionHasNoErrors();
        $this->post($route, $data)->assertRedirect('/bank')->assertSessionHasNoErrors();
        $this->assertSame(8000, app(BuildCashBalance::class)->handle()['cashCents']);

        $transfer = CashTransfer::query()->firstOrFail();
        $this->actingAs($this->treasury())
            ->post("/bank/transfers/{$transfer->id}/void", ['reason' => 'No era una retirada'])
            ->assertRedirect('/bank');

        $voided = CashTransfer::query()->firstOrFail();
        $this->assertNotNull($voided->voided_at);
        $this->assertSame('No era una retirada', $voided->void_reason);

        $this->postJson($route, $data)->assertUnprocessable()
            ->assertJsonPath('errors.submission_key.0', 'El formulario ha caducado: anulaste este traspaso. Recarga la página y vuelve a marcarlo.');

        $this->assertSame(1, CashTransfer::query()->count());
        $this->assertSame(0, app(BuildCashBalance::class)->handle()['cashCents']);
    }

    /** Rule 7: a credit marked as a transfer is no longer waiting to be reconciled. */
    public function test_a_deposit_is_no_longer_an_unreconciled_credit(): void
    {
        $deposit = $this->movement(3000, 'INGRESO EFECTIVO');
        app(MarkCashTransfer::class)->handle($deposit, 'to_bank', 'Ingreso del sobrante', (string) Str::uuid(), (int) $this->treasury()->id);

        $this->actingAs($this->board())->get('/saldos')
            ->assertOk()
            ->assertSee('0 abonos sin conciliar')
            ->assertSeeInOrder(['Sin conciliar', '0,00 €']);
    }

    /** Rule 8: the board reads and never writes. */
    public function test_board_reads_but_never_writes(): void
    {
        $withdrawal = $this->movement(-8000);
        $transfer = app(MarkCashTransfer::class)->handle($withdrawal, 'to_cash', 'Retirada', (string) Str::uuid(), (int) $this->treasury()->id);

        $this->actingAs($this->board())->get('/bank')
            ->assertOk()
            ->assertSee('Retirada')
            ->assertDontSee('Marcar traspaso');

        $this->actingAs($this->board())
            ->post("/bank/movements/{$withdrawal->id}/transfers", [
                'submission_key' => (string) Str::uuid(),
                'direction' => 'to_cash',
                'description' => 'Intento de junta',
            ])
            ->assertForbidden();

        $this->actingAs($this->board())
            ->post("/bank/transfers/{$transfer->id}/void", ['reason' => 'Intento de junta'])
            ->assertForbidden();

        $this->assertNull(CashTransfer::query()->firstOrFail()->voided_at);
    }
}
