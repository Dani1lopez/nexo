<?php

namespace Tests\Feature;

use App\Modules\Access\Models\User;
use App\Modules\Bank\Models\BankImport;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Cash\Actions\BuildCashBalance;
use App\Modules\Cash\Actions\UpdateCashExpense;
use App\Modules\Cash\Models\CashExpense;
use App\Modules\Fees\Actions\RegisterPayment;
use App\Modules\Fees\Actions\SetFeeYear;
use App\Modules\Fees\Models\Due;
use App\Modules\Members\Models\Member;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\AuthTestCase;

/**
 * Issue #62: cash expenses. A draft never moves the cash figure, confirming subtracts exactly once,
 * voiding with a reason returns the money and keeps the row, and the bank balance never moves.
 */
class CashExpenseTest extends AuthTestCase
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

    /**
     * @return array<string, mixed>
     */
    private function expenseInput(array $overrides = []): array
    {
        return [
            'spent_on' => '2026-04-10',
            'amount' => '25,00',
            'category' => 'material',
            'concept' => 'Material para el taller',
            'has_receipt' => '1',
            'reason' => null,
            'submission_key' => (string) Str::uuid(),
            ...$overrides,
        ];
    }

    private function draft(array $overrides = []): CashExpense
    {
        $this->actingAs($this->treasury())
            ->post('/caja/gastos', $this->expenseInput($overrides))
            ->assertRedirect('/caja');

        return CashExpense::query()->firstOrFail();
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

    /** Rule 1: a draft is registered but the cash figure waits for its confirmation. */
    public function test_treasury_registers_a_draft_that_waits_for_confirmation(): void
    {
        $this->actingAs($this->treasury())
            ->post('/caja/gastos', $this->expenseInput())
            ->assertRedirect('/caja');

        $this->actingAs($this->treasury())->get('/caja')
            ->assertOk()
            ->assertSee('Gasto en borrador registrado.')
            ->assertSee('Borrador')
            ->assertSee('Material para el taller')
            ->assertSeeInOrder(['Saldo en caja', '0,00 €']);

        $this->assertSame(null, CashExpense::query()->firstOrFail()->confirmed_at);
    }

    /** Rule 2: confirming subtracts once and a repeated submit is harmless. */
    public function test_confirming_a_draft_subtracts_once_and_a_repeated_submit_is_harmless(): void
    {
        $this->draft(['amount' => '25,00']);
        $expense = CashExpense::query()->firstOrFail();

        $this->actingAs($this->treasury())->post("/caja/gastos/{$expense->id}/confirm")
            ->assertRedirect('/caja');

        $this->actingAs($this->treasury())->post("/caja/gastos/{$expense->id}/confirm")
            ->assertRedirect('/caja');

        $this->actingAs($this->treasury())->get('/caja')
            ->assertOk()
            ->assertSee('ya estaba confirmado')
            ->assertSeeInOrder(['Saldo en caja', '-25,00 €']);

        $this->assertSame(1, CashExpense::query()->count());
    }

    /** Rule 3: the spec example. Cash drops and the bank does not move. */
    public function test_the_cash_figure_drops_but_the_bank_stays(): void
    {
        $ana = Member::create(['name' => 'Ana ficticia', 'email' => 'ana@example.test', 'joined_on' => '2024-01-15']);
        $year = app(SetFeeYear::class)->handle(2026, 3000);
        $this->movement(10000, 'CUOTA ANA');
        $due = Due::where('member_id', $ana->id)->where('fee_year_id', $year->id)->firstOrFail();
        app(RegisterPayment::class)->handle($due, 3000, '2026-01-10', 'cash', null, 'key-cash-fee');

        $this->actingAs($this->treasury())->post('/caja/saldo-inicial', ['amount' => '80,00']);
        $this->draft(['amount' => '25,00']);
        $expense = CashExpense::query()->firstOrFail();
        $this->actingAs($this->treasury())->post("/caja/gastos/{$expense->id}/confirm");

        $this->actingAs($this->board())->get('/saldos')
            ->assertOk()
            ->assertSeeInOrder(['Saldo en caja', '85,00 €'])
            ->assertSeeInOrder(['Saldo en el banco', '100,00 €'])
            ->assertSee('Los pagos en efectivo no cambian este saldo.');
    }

    /** Rule 4: voiding with a reason returns the money and never deletes the row. */
    public function test_voiding_returns_the_money_and_keeps_the_row(): void
    {
        $this->actingAs($this->treasury())->post('/caja/saldo-inicial', ['amount' => '80,00']);
        $this->draft(['amount' => '25,00']);
        $expense = CashExpense::query()->firstOrFail();
        $this->actingAs($this->treasury())->post("/caja/gastos/{$expense->id}/confirm");

        $this->actingAs($this->treasury())->get('/caja')->assertSee('55,00 €');

        $this->actingAs($this->treasury())
            ->post("/caja/gastos/{$expense->id}/void", ['reason' => 'Se anula: el importe era otro.'])
            ->assertRedirect('/caja');

        $this->actingAs($this->treasury())->get('/caja')
            ->assertOk()
            ->assertSee('80,00 €')
            ->assertSee('Anulado')
            ->assertSee('Se anula: el importe era otro.');

        $voided = CashExpense::query()->firstOrFail();
        $this->assertNotNull($voided->voided_at);
        $this->assertSame('Se anula: el importe era otro.', $voided->void_reason);
    }

    /** Rule 5: a draft can be edited; a confirmed expense can only be voided. */
    public function test_a_draft_can_be_edited_but_not_a_confirmed_expense(): void
    {
        $expense = $this->draft();

        $this->actingAs($this->treasury())
            ->put("/caja/gastos/{$expense->id}", $this->expenseInput([
                'amount' => '30,00',
                'concept' => 'Material del taller de abril',
                'submission_key' => null,
            ]))
            ->assertRedirect('/caja');

        $edited = CashExpense::query()->firstOrFail();
        $this->assertSame(3000, $edited->amount_cents);
        $this->assertSame('Material del taller de abril', $edited->concept);
        $this->assertSame(null, $edited->confirmed_at);

        $this->actingAs($this->treasury())->post("/caja/gastos/{$edited->id}/confirm");

        $this->actingAs($this->treasury())
            ->put("/caja/gastos/{$edited->id}", $this->expenseInput(['concept' => 'Cambio tardío', 'submission_key' => null]))
            ->assertSessionHasErrors();

        $this->assertSame('Material del taller de abril', CashExpense::query()->firstOrFail()->concept);
    }

    /** Rule 6: without a receipt the reason is mandatory. */
    public function test_a_reason_is_required_without_a_receipt(): void
    {
        $this->actingAs($this->treasury())
            ->post('/caja/gastos', $this->expenseInput(['has_receipt' => null, 'reason' => null]))
            ->assertSessionHasErrors(['reason' => 'Indica el motivo del gasto: no hay justificante.']);

        $this->assertSame(0, CashExpense::query()->count());

        $this->actingAs($this->treasury())
            ->post('/caja/gastos', $this->expenseInput(['has_receipt' => null, 'reason' => 'Ticket perdido']))
            ->assertRedirect('/caja');

        $this->assertSame(1, CashExpense::query()->count());
    }

    /** Rule 7: the board reads everything and writes nothing. */
    public function test_board_reads_but_never_writes(): void
    {
        $expense = $this->draft();

        $this->actingAs($this->board())->get('/caja')
            ->assertOk()
            ->assertSee('Material para el taller')
            ->assertDontSee('Guardar en borrador')
            ->assertDontSee('Fijar saldo inicial')
            ->assertDontSee('Anular gasto')
            ->assertDontSee('Actualizar borrador');

        $this->actingAs($this->board())->post('/caja/gastos', $this->expenseInput())->assertForbidden();
        $this->actingAs($this->board())->post("/caja/gastos/{$expense->id}/confirm")->assertForbidden();
        $this->actingAs($this->board())->post("/caja/gastos/{$expense->id}/void", ['reason' => 'No debería'])->assertForbidden();
    }

    /** Rule 8: future dates, zero or negative amounts, unknown categories and a missing concept are rejected. */
    public function test_invalid_expense_input_is_rejected(): void
    {
        $this->actingAs($this->treasury())
            ->post('/caja/gastos', $this->expenseInput(['spent_on' => '2100-01-01']))
            ->assertSessionHasErrors(['spent_on' => 'La fecha del gasto no puede ser futura.']);

        $this->actingAs($this->treasury())
            ->post('/caja/gastos', $this->expenseInput(['amount' => '0,00']))
            ->assertSessionHasErrors(['amount']);

        $this->actingAs($this->treasury())
            ->post('/caja/gastos', $this->expenseInput(['category' => 'desconocida']))
            ->assertSessionHasErrors(['category' => 'La categoría del gasto no es válida.']);

        $this->actingAs($this->treasury())
            ->post('/caja/gastos', $this->expenseInput(['concept' => null]))
            ->assertSessionHasErrors(['concept' => 'Indica el concepto del gasto.']);

        $this->assertSame(0, CashExpense::query()->count());
    }

    /** Preserve (#62): the opening balance and the cash fee payments keep their own rules. */
    public function test_a_voided_expense_still_leaves_the_fee_payments_counted(): void
    {
        $ana = Member::create(['name' => 'Ana ficticia', 'email' => 'ana@example.test', 'joined_on' => '2024-01-15']);
        $year = app(SetFeeYear::class)->handle(2026, 3000);
        $due = Due::where('member_id', $ana->id)->where('fee_year_id', $year->id)->firstOrFail();
        app(RegisterPayment::class)->handle($due, 3000, '2026-01-10', 'cash', null, 'key-cash-fee-2');

        $this->actingAs($this->treasury())->post('/caja/saldo-inicial', ['amount' => '50,00']);
        $expense = $this->draft();
        $this->actingAs($this->treasury())->post("/caja/gastos/{$expense->id}/confirm");
        $this->actingAs($this->treasury())->post("/caja/gastos/{$expense->id}/void", ['reason' => 'Anulado para repetirlo']);

        $this->actingAs($this->board())->get('/caja')
            ->assertOk()
            ->assertSeeInOrder(['Saldo en caja', '80,00 €'])
            ->assertSeeInOrder(['Cuotas en efectivo', '30,00 €']);
    }

    /** Rule 9: a reused form only replays as a retry when the details are identical (R1-002, R3-002, R4-002). */
    public function test_a_reused_form_with_different_details_is_rejected_and_an_identical_one_is_a_retry(): void
    {
        $input = $this->expenseInput(['amount' => '25,00']);
        $this->actingAs($this->treasury())->post('/caja/gastos', $input)->assertRedirect('/caja');

        // Same key, different amount: the original draft is the truth and nothing new is registered.
        $this->actingAs($this->treasury())
            ->post('/caja/gastos', [...$input, 'amount' => '30,00'])
            ->assertSessionHasErrors(['submission_key' => 'El formulario ya se había enviado y sus datos han cambiado. Recarga la página y vuelve a intentarlo.']);

        $this->assertSame(1, CashExpense::query()->count());
        $this->assertSame(2500, CashExpense::query()->firstOrFail()->amount_cents);

        // The very same submission is still a harmless retry, not a second draft.
        $this->actingAs($this->treasury())->post('/caja/gastos', $input)->assertRedirect('/caja');
        $this->assertSame(1, CashExpense::query()->count());
    }

    /** Rule 11 (REG-001): an exact replay still succeeds after the draft was legitimately edited. */
    public function test_an_exact_replay_still_succeeds_after_the_draft_is_edited(): void
    {
        $input = $this->expenseInput(['amount' => '25,00']);
        $this->actingAs($this->treasury())->post('/caja/gastos', $input)->assertRedirect('/caja');
        $expense = CashExpense::query()->firstOrFail();

        // A legitimate edit changes the draft; the original form key stays on the row.
        $this->actingAs($this->treasury())
            ->put("/caja/gastos/{$expense->id}", $this->expenseInput([
                'amount' => '30,00',
                'concept' => 'Material del taller de abril',
                'submission_key' => null,
            ]))
            ->assertRedirect('/caja');

        // Replaying the ORIGINAL registration is still the same submission, not a second draft.
        $this->actingAs($this->treasury())->post('/caja/gastos', $input)->assertRedirect('/caja');

        $this->assertSame(1, CashExpense::query()->count());
        $row = CashExpense::query()->firstOrFail();
        $this->assertSame(3000, $row->amount_cents);
        $this->assertSame('Material del taller de abril', $row->concept);
    }

    /** Rule 12: the fingerprint encodes field boundaries unambiguously, so crafted details never collide (R1-001, R3-001). */
    public function test_crafted_details_that_serialize_the_same_are_still_rejected(): void
    {
        $first = $this->expenseInput([
            'has_receipt' => null,
            'concept' => 'Material',
            'reason' => '0|Ticket perdido',
        ]);
        $this->actingAs($this->treasury())->post('/caja/gastos', $first)->assertRedirect('/caja');

        // A different submission that used to serialize to the very same string.
        $this->actingAs($this->treasury())
            ->post('/caja/gastos', [...$first, 'concept' => 'Material|0', 'reason' => 'Ticket perdido'])
            ->assertSessionHasErrors(['submission_key' => 'El formulario ya se había enviado y sus datos han cambiado. Recarga la página y vuelve a intentarlo.']);

        $row = CashExpense::query()->firstOrFail();
        $this->assertSame('Material', $row->concept);
        $this->assertSame('0|Ticket perdido', $row->reason);
        $this->assertSame(1, CashExpense::query()->count());
    }

    /** Rule 13: a failed edit keeps the treasurer's unchecked receipt box unchecked (R4-001). */
    public function test_a_failed_edit_does_not_silently_recheck_the_receipt_box(): void
    {
        $expense = $this->draft(['has_receipt' => '1']);

        // Unchecking the receipt without a reason is refused.
        $response = $this->actingAs($this->treasury())
            ->put("/caja/gastos/{$expense->id}", $this->expenseInput([
                'has_receipt' => null,
                'reason' => null,
                'submission_key' => null,
            ]));
        $response->assertSessionHasErrors(['reason']);

        // The form shown back keeps the treasurer's choice: the box stays unchecked.
        $page = $this->actingAs($this->treasury())->get("/caja/gastos/{$expense->id}/edit");
        $page->assertOk();
        $this->assertStringContainsString('name="has_receipt" value="0"', $page->getContent());
        $this->assertStringNotContainsString('checked', $page->getContent());
    }

    /** Rule 10: a stale edit loaded before a confirmation can never rewrite the confirmed row (R1-001, R3-001, R4-001). */
    public function test_a_stale_edit_cannot_change_a_confirmed_expense(): void
    {
        $this->draft(['amount' => '25,00']);
        $stale = CashExpense::query()->firstOrFail();

        // The confirmation lands between the moment the edit loaded the row and its save.
        $this->actingAs($this->treasury())->post("/caja/gastos/{$stale->id}/confirm")->assertRedirect('/caja');

        try {
            app(UpdateCashExpense::class)->handle(
                $stale,
                3000,
                '2026-04-10',
                'material',
                'Importe cambiado en una pestaña antigua',
                true,
                null,
            );
            $this->fail('A stale edit should have been refused.');
        } catch (ValidationException $e) {
            $this->assertSame('El gasto está confirmado y no se puede editar: anúlalo con motivo y registra uno nuevo.', $e->errors()['concept'][0]);
        }

        $row = CashExpense::query()->firstOrFail();
        $this->assertSame(2500, $row->amount_cents);
        $this->assertSame('Material para el taller', $row->concept);
        $this->assertNotNull($row->confirmed_at);

        // The refusal also leaves the cash figure untouched (R3-001): the confirmed €25 is what
        // the balance deducts, never the €30 the stale edit tried to write.
        $this->assertSame(-2500, app(BuildCashBalance::class)->handle()['cashCents']);
    }
}
