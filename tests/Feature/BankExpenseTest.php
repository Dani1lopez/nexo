<?php

namespace Tests\Feature;

use App\Modules\Access\Models\User;
use App\Modules\Bank\Actions\ClassifyBankExpense;
use App\Modules\Bank\Models\BankExpense;
use App\Modules\Bank\Models\BankImport;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Bank\Models\OtherIncome;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\AuthTestCase;

/**
 * Issue #65: a complete charge of the statement can be classified as an expense, which explains
 * what the money was spent on without changing the bank balance or the cash figure.
 */
class BankExpenseTest extends AuthTestCase
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

    private function movement(int $cents, string $concept = 'COMPRA MATERIAL', string $date = '2026-03-01'): BankMovement
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

    private function classify(BankMovement $movement, string $category = 'material', string $description = 'Material de taller'): BankExpense
    {
        return app(ClassifyBankExpense::class)->handle($movement, $category, $description, (string) Str::uuid(), (int) $this->treasury()->id);
    }

    /** Rule 1: a complete charge is classified with its absolute amount and never moves the bank. */
    public function test_a_charge_is_classified_and_the_bank_balance_does_not_move(): void
    {
        $charge = $this->movement(-2550);

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$charge->id}/expenses", [
                'submission_key' => (string) Str::uuid(),
                'category' => 'material',
                'description' => 'Material de taller',
            ])
            ->assertRedirect('/bank');

        $expense = BankExpense::query()->firstOrFail();
        $this->assertSame(2550, $expense->amount_cents);
        $this->assertSame('material', $expense->category);
        $this->assertSame(-2550, $charge->fresh()->amount_cents);

        $this->actingAs($this->board())->get('/saldos')
            ->assertOk()
            ->assertSeeInOrder(['Saldo en el banco', '-25,50 €'])
            ->assertSeeInOrder(['Gastos bancarios', '25,50 €']);
    }

    /** Rule 2: only charges are classifiable; a credit keeps its own destinations. */
    public function test_a_credit_cannot_be_classified_as_an_expense(): void
    {
        $credit = $this->movement(3000, 'TRANSFERENCIA ANA');

        $this->expectException(ValidationException::class);

        $this->classify($credit);
    }

    /** Rule 3: one live classification per charge, also when the form is submitted twice with other values. */
    public function test_one_live_classification_per_charge_and_a_changed_replay_is_refused(): void
    {
        $charge = $this->movement(-2550);
        $key = (string) Str::uuid();

        $first = app(ClassifyBankExpense::class)->handle($charge, 'material', 'Material de taller', $key, (int) $this->treasury()->id);
        $again = app(ClassifyBankExpense::class)->handle($charge, 'material', 'Material de taller', $key, (int) $this->treasury()->id);

        $this->assertTrue($first->is($again));
        $this->assertSame(1, BankExpense::query()->count());

        try {
            app(ClassifyBankExpense::class)->handle($charge, 'activities', 'Otra cosa', $key, (int) $this->treasury()->id);
            $this->fail('A reused form with other values should have been refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ya se usó con otra categoría', $e->errors()['submission_key'][0]);
        }

        try {
            app(ClassifyBankExpense::class)->handle($charge, 'other', 'Segundo intento', (string) Str::uuid(), (int) $this->treasury()->id);
            $this->fail('A second live classification should have been refused.');
        } catch (ValidationException $e) {
            $this->assertSame('El cargo ya está clasificado como gasto.', $e->errors()['movement'][0]);
        }

        $this->assertSame(1, BankExpense::query()->count());
    }

    /** Rule 4: voiding with a reason keeps the row, frees the charge and leaves the balance alone. */
    public function test_voiding_keeps_the_history_and_frees_the_charge(): void
    {
        $charge = $this->movement(-2550);
        $expense = $this->classify($charge);

        $this->actingAs($this->treasury())
            ->post("/bank/expenses/{$expense->id}/void", ['reason' => 'Era otro gasto'])
            ->assertRedirect('/bank');

        $voided = BankExpense::query()->firstOrFail();
        $this->assertNotNull($voided->voided_at);
        $this->assertSame('Era otro gasto', $voided->void_reason);

        // The charge can be classified again once the live one is voided.
        $this->classify($charge, 'other', 'Segundo intento');
        $this->assertSame(2, BankExpense::query()->count());

        $this->actingAs($this->board())->get('/saldos')
            ->assertOk()
            ->assertSeeInOrder(['Gastos bancarios', '25,50 €'])
            ->assertSeeInOrder(['Saldo en el banco', '-25,50 €']);
    }

    /** Rule 5: the board reads the page and never writes. */
    public function test_board_reads_but_never_writes(): void
    {
        $charge = $this->movement(-2550);
        $expense = $this->classify($charge);

        $this->actingAs($this->board())->get('/bank')
            ->assertOk()
            ->assertSee('Material de taller')
            ->assertDontSee('Clasificar el cargo');

        $this->actingAs($this->board())
            ->post("/bank/movements/{$charge->id}/expenses", [
                'submission_key' => (string) Str::uuid(),
                'category' => 'material',
                'description' => 'Intento de junta',
            ])
            ->assertForbidden();

        $this->actingAs($this->board())
            ->post("/bank/expenses/{$expense->id}/void", ['reason' => 'Intento de junta'])
            ->assertForbidden();

        $this->assertSame(1, BankExpense::query()->count());
        $this->assertNull(BankExpense::query()->firstOrFail()->voided_at);
    }

    /** Rule 6: the form needs a known category and a description. */
    public function test_the_category_and_description_are_required(): void
    {
        $charge = $this->movement(-2550);

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$charge->id}/expenses", [
                'submission_key' => (string) Str::uuid(),
                'category' => 'desconocida',
                'description' => '',
            ])
            ->assertSessionHasErrors(['category', 'description']);

        $this->assertSame(0, BankExpense::query()->count());
    }

    /** Preserve (#44): the credit classification keeps refusing a charge. */
    public function test_a_charge_is_still_not_other_income(): void
    {
        $charge = $this->movement(-2550);

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$charge->id}/incomes", [
                'submission_key' => (string) Str::uuid(),
                'category' => 'donations',
                'description' => 'No es un ingreso',
            ])
            ->assertSessionHasErrors(['movement']);

        $this->assertSame(0, OtherIncome::query()->count());
    }
}
