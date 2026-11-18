<?php

namespace Tests\Feature;

use App\Modules\Access\Models\User;
use App\Modules\Bank\Actions\ClassifyOtherIncome;
use App\Modules\Bank\Actions\ReconcileMovement;
use App\Modules\Bank\Models\BankImport;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Bank\Models\OtherIncome;
use App\Modules\Fees\Actions\RegisterPayment;
use App\Modules\Fees\Actions\SetFeeYear;
use App\Modules\Fees\Actions\VoidPayment;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\FeeYear;
use App\Modules\Fees\Models\Payment;
use App\Modules\Members\Models\Member;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Support\AuthTestCase;

/**
 * Issue #44: a complete imported credit can be classified as other income instead of paying dues.
 * Both destinations use the same money, so neither may ever be assigned twice.
 */
class OtherIncomeTest extends AuthTestCase
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

    private function movement(int $cents, string $concept = 'DONACION MERCADILLO', string $date = '2026-02-14'): BankMovement
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

    /**
     * Posts a classification; the defaults describe a valid one and each case overrides what it needs.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function classify(int $movementId, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->treasury())->post(
            "/bank/movements/{$movementId}/incomes",
            array_merge([
                'submission_key' => (string) Str::uuid(),
                'category' => 'donations',
                'description' => 'Donación del mercadillo',
            ], $overrides),
        );
    }

    private function income(): OtherIncome
    {
        return OtherIncome::firstOrFail();
    }

    /** S1, S2 and S4: the credit is classified and the bank balance does not move. */
    public function test_treasury_classifies_a_complete_credit_and_the_balance_does_not_change(): void
    {
        $credit = $this->movement(10000, 'DONACION MERCADILLO');
        $this->movement(-2500, 'COMPRA MATERIAL');

        $this->actingAs($this->board())->get('/saldos')
            ->assertOk()
            ->assertSeeInOrder(['Saldo en el banco', '75,00 €'])
            ->assertSee('1 abono sin conciliar');

        $this->classify($credit->id, ['description' => 'Donación del mercadillo de primavera'])
            ->assertRedirect('/bank')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Abono clasificado como otro ingreso.');

        $this->assertDatabaseHas('other_incomes', [
            'bank_movement_id' => $credit->id,
            'category' => 'donations',
            'description' => 'Donación del mercadillo de primavera',
            'amount_cents' => 10000,
            'user_id' => $this->treasury()->id,
            'voided_at' => null,
            'void_reason' => null,
        ]);

        // S4: the statement balance is untouched and the credit is no longer pending reconciliation.
        $this->actingAs($this->board())->get('/saldos')
            ->assertOk()
            ->assertSeeInOrder(['Saldo en el banco', '75,00 €'])
            ->assertSee('0 abonos sin conciliar')
            ->assertSeeInOrder(['Otros ingresos', '100,00 €'])
            ->assertSee('1 ingreso clasificado')
            ->assertSee('Ya incluidos en el saldo bancario.');

        $this->actingAs($this->board())->get('/bank')
            ->assertOk()
            ->assertSee('Clasificado como otro ingreso · Donaciones · 100,00 €')
            ->assertSee('Donación del mercadillo de primavera');
    }

    /** S1: a charge is never income. */
    public function test_a_charge_cannot_be_classified(): void
    {
        $charge = $this->movement(-1500, 'RECIBO DEVUELTO');

        $this->classify($charge->id)
            ->assertSessionHasErrors('movement')
            ->assertSessionMissing('status');

        $this->from('/bank')->followingRedirects()
            ->post("/bank/movements/{$charge->id}/incomes", [
                'submission_key' => (string) Str::uuid(),
                'category' => 'other',
                'description' => 'No es un ingreso',
            ])
            ->assertOk()
            ->assertSee('Solo se pueden clasificar abonos');

        $this->assertDatabaseCount('other_incomes', 0);
    }

    /** S1: a credit already assigned to dues cannot also become other income. */
    public function test_a_partially_reconciled_credit_cannot_be_classified(): void
    {
        $year = $this->year(2026, 1000);
        $ana = $this->dueFor($this->member(), $year);
        $luis = $this->dueFor($this->member('luis@example.test', 'Luis ficticio'), $year);
        $movement = $this->movement(2000, 'TRANSFERENCIA CONJUNTA');
        app(ReconcileMovement::class)->handle($movement, [$ana->id => 1000, $luis->id => 1000], (string) Str::uuid());

        // Voiding one part leaves the credit half assigned: it is not whole any more.
        app(VoidPayment::class)->handle(Payment::where('due_id', $ana->id)->firstOrFail(), 'Se repartió mal.');

        $this->classify($movement->id)->assertSessionHasErrors('movement');

        $this->assertDatabaseCount('other_incomes', 0);
        $this->assertSame(2, Payment::where('bank_movement_id', $movement->id)->count());
        $this->assertSame(0, $ana->fresh()->paidCents());
        $this->assertSame(1000, $luis->fresh()->paidCents());
    }

    /** S1: a credit spent in full on dues is not free either. */
    public function test_a_fully_reconciled_credit_cannot_be_classified(): void
    {
        $year = $this->year(2026, 3000);
        $due = $this->dueFor($this->member(), $year);
        $movement = $this->movement(3000, 'TRANSFERENCIA ANA');
        app(ReconcileMovement::class)->handle($movement, [$due->id => 3000], (string) Str::uuid());

        $this->classify($movement->id)->assertSessionHasErrors('movement');

        $this->assertDatabaseCount('other_incomes', 0);
        $this->assertSame('paid', $due->fresh()->status());
    }

    /** S2: only the predefined list is accepted, and each of its entries works. */
    public function test_only_the_predefined_categories_are_accepted(): void
    {
        $this->classify($this->movement(1000)->id, ['category' => 'cuotas'])
            ->assertSessionHasErrors('category');

        $this->assertDatabaseCount('other_incomes', 0);

        $expected = [
            'donations' => 'Donaciones',
            'activities' => 'Actividades',
            'grants' => 'Subvenciones',
            'other' => 'Otros',
        ];

        foreach ($expected as $category => $label) {
            $movement = $this->movement(1000, 'INGRESO '.mb_strtoupper($category));

            $this->classify($movement->id, ['category' => $category])->assertSessionHasNoErrors();

            $this->assertDatabaseHas('other_incomes', [
                'bank_movement_id' => $movement->id,
                'category' => $category,
            ]);

            $this->actingAs($this->board())->get('/bank')->assertOk()->assertSee($label);
        }

        $this->assertSame(4, OtherIncome::count());
    }

    /** S2: the description is mandatory and cannot be only blanks. */
    public function test_the_description_is_required(): void
    {
        $movement = $this->movement(1000);

        $this->classify($movement->id, ['description' => ''])
            ->assertSessionHasErrors('description');
        $this->classify($movement->id, ['description' => '   '])
            ->assertSessionHasErrors('description');

        $without = $this->actingAs($this->treasury())->post("/bank/movements/{$movement->id}/incomes", [
            'submission_key' => (string) Str::uuid(),
            'category' => 'donations',
        ]);
        $without->assertSessionHasErrors('description');

        $this->assertDatabaseCount('other_incomes', 0);
    }

    /** S3 and S7: board consults; only treasury classifies and voids. */
    public function test_board_consults_but_cannot_classify_or_void(): void
    {
        $movement = $this->movement(1500);
        $this->classify($movement->id)->assertSessionHasNoErrors();
        $income = $this->income();

        $this->actingAs($this->board())->get('/bank')
            ->assertOk()
            ->assertSee('Clasificado como otro ingreso')
            ->assertDontSee('Clasificar como otro ingreso')
            ->assertDontSee('Anular la clasificación');

        $this->actingAs($this->board())->post("/bank/movements/{$this->movement(1000)->id}/incomes", [
            'submission_key' => (string) Str::uuid(),
            'category' => 'other',
            'description' => 'Intento de la junta',
        ])->assertForbidden();

        $this->actingAs($this->board())->post("/bank/incomes/{$income->id}/void", ['reason' => 'Intento de la junta'])
            ->assertForbidden();

        $this->assertSame(1, OtherIncome::count());
        $this->assertNull($income->fresh()->voided_at);
        $this->assertSame(0, OtherIncome::where('description', 'Intento de la junta')->count());
    }

    /** S7: the new routes are closed to anonymous visitors and write nothing. */
    public function test_anonymous_visitors_are_sent_to_login(): void
    {
        $official = $this->treasury();
        $movement = $this->movement(1500);
        $income = app(ClassifyOtherIncome::class)->handle(
            $movement,
            'donations',
            'Clasificación existente',
            (string) Str::uuid(),
            $official->id,
        );

        $this->post("/bank/incomes/{$income->id}/void", ['reason' => 'Sin sesión'])->assertRedirect('/login');
        $this->assertNull($income->fresh()->voided_at);

        $free = $this->movement(1000, 'DONACION SIN SESION');

        $this->post("/bank/movements/{$free->id}/incomes", [
            'submission_key' => (string) Str::uuid(),
            'category' => 'donations',
            'description' => 'Sin sesión',
        ])->assertRedirect('/login');

        $this->assertDatabaseCount('other_incomes', 1);
        $this->assertSame(0, OtherIncome::where('description', 'Sin sesión')->count());
    }

    /** S3: voiding keeps the history and frees the credit for a new destination. */
    public function test_treasury_voids_with_a_reason_and_frees_the_credit(): void
    {
        $year = $this->year(2026, 2000);
        $due = $this->dueFor($this->member(), $year);
        $movement = $this->movement(2000);
        $this->classify($movement->id)->assertSessionHasNoErrors();
        $income = $this->income();

        $this->actingAs($this->treasury())
            ->post("/bank/incomes/{$income->id}/void", ['reason' => 'Era la cuota de Ana'])
            ->assertRedirect('/bank')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Clasificación anulada: el abono vuelve a estar pendiente de conciliación.');

        $this->assertNotNull($income->fresh()->voided_at);
        $this->assertSame('Era la cuota de Ana', $income->fresh()->void_reason);

        // The credit is offered again as pending.
        $this->actingAs($this->board())->get('/saldos')
            ->assertOk()
            ->assertSee('1 abono sin conciliar')
            ->assertSee('20,00 €')
            ->assertDontSee('Otros ingresos');

        $this->actingAs($this->treasury())->get('/bank')
            ->assertOk()
            ->assertSee('Quedan 20,00 € por conciliar')
            ->assertSee('Clasificar como otro ingreso');

        // And it can now pay a due, like any other free credit.
        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/reconcile", [
                'submission_key' => (string) Str::uuid(),
                'allocations' => [$due->id => '20'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('paid', $due->fresh()->status());
        $this->assertDatabaseCount('other_incomes', 1);
    }

    /** S3: a void needs its reason and cannot be repeated. */
    public function test_void_requires_a_reason_and_cannot_be_repeated(): void
    {
        $movement = $this->movement(1500);
        $this->classify($movement->id)->assertSessionHasNoErrors();
        $income = $this->income();

        $this->actingAs($this->treasury())
            ->post("/bank/incomes/{$income->id}/void", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertNull($income->fresh()->voided_at);

        $this->actingAs($this->treasury())
            ->post("/bank/incomes/{$income->id}/void", ['reason' => 'Primera anulación'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->treasury())
            ->post("/bank/incomes/{$income->id}/void", ['reason' => 'Segunda anulación'])
            ->assertSessionHasErrors('reason');

        $this->assertSame('Primera anulación', $income->fresh()->void_reason);
        $this->assertDatabaseCount('other_incomes', 1);
    }

    /** S5: resending the same form is not a second classification. */
    public function test_resubmitting_the_same_form_does_not_create_a_second_income(): void
    {
        $movement = $this->movement(1500);
        $form = [
            'submission_key' => (string) Str::uuid(),
            'category' => 'activities',
            'description' => 'Taller de fotografía',
        ];

        $this->actingAs($this->treasury())->post("/bank/movements/{$movement->id}/incomes", $form)
            ->assertSessionHasNoErrors();

        $this->actingAs($this->treasury())->post("/bank/movements/{$movement->id}/incomes", $form)
            ->assertRedirect('/bank')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Abono clasificado como otro ingreso.');

        $this->assertDatabaseCount('other_incomes', 1);
        $this->assertSame(1500, (int) OtherIncome::sum('amount_cents'));
    }

    /**
     * S5 and S7: the same form replayed with another category is refused, not reported as success,
     * and the credit keeps the classification it already had.
     */
    public function test_resending_the_form_with_another_category_is_refused_and_keeps_the_original(): void
    {
        $movement = $this->movement(1500);
        $form = [
            'submission_key' => (string) Str::uuid(),
            'category' => 'donations',
            'description' => 'Donación del mercadillo',
        ];

        $this->actingAs($this->treasury())->post("/bank/movements/{$movement->id}/incomes", $form)
            ->assertSessionHasNoErrors();

        $income = $this->income();

        $this->from('/bank')->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/incomes", ['category' => 'activities'] + $form)
            ->assertRedirect('/bank')
            ->assertSessionHasErrors('submission_key')
            ->assertSessionMissing('status');

        $this->from('/bank')->followingRedirects()
            ->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/incomes", ['category' => 'activities'] + $form)
            ->assertOk()
            ->assertSee('El abono conserva su clasificación original')
            ->assertSee('anula la clasificación');

        $stored = $income->fresh();
        $this->assertNotNull($stored);
        $this->assertSame('donations', $stored->category);
        $this->assertSame('Donación del mercadillo', $stored->description);
        $this->assertNull($stored->voided_at);
        $this->assertSame($income->updated_at?->toDateTimeString(), $stored->updated_at?->toDateTimeString());
        $this->assertDatabaseCount('other_incomes', 1);

        // The bank screen still shows the original classification, not the refused one.
        $this->actingAs($this->treasury())->get('/bank')
            ->assertOk()
            ->assertSee('Donaciones')
            ->assertSee('Donación del mercadillo')
            ->assertDontSee('Actividades');
    }

    /** S5 and S7: the same form replayed with another description is refused the same way. */
    public function test_resending_the_form_with_another_description_is_refused_and_keeps_the_original(): void
    {
        $movement = $this->movement(2000);
        $form = [
            'submission_key' => (string) Str::uuid(),
            'category' => 'grants',
            'description' => 'Subvención municipal',
        ];

        $this->actingAs($this->treasury())->post("/bank/movements/{$movement->id}/incomes", $form)
            ->assertSessionHasNoErrors();

        $income = $this->income();

        $this->from('/bank')->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/incomes", ['description' => 'Subvención provincial'] + $form)
            ->assertRedirect('/bank')
            ->assertSessionHasErrors('submission_key')
            ->assertSessionMissing('status');

        $this->from('/bank')->followingRedirects()
            ->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/incomes", ['description' => 'Subvención provincial'] + $form)
            ->assertOk()
            ->assertSee('El abono conserva su clasificación original');

        $stored = $income->fresh();
        $this->assertNotNull($stored);
        $this->assertSame('grants', $stored->category);
        $this->assertSame('Subvención municipal', $stored->description);
        $this->assertNull($stored->voided_at);
        $this->assertSame($income->updated_at?->toDateTimeString(), $stored->updated_at?->toDateTimeString());
        $this->assertDatabaseCount('other_incomes', 1);

        $this->actingAs($this->treasury())->get('/bank')
            ->assertOk()
            ->assertSee('Subvención municipal')
            ->assertDontSee('Subvención provincial');
    }

    /** S5: a form key rendered for another credit is still refused and classifies nothing there. */
    public function test_a_form_key_from_another_credit_is_refused(): void
    {
        $first = $this->movement(1500, 'DONACION UNO');
        $second = $this->movement(1500, 'DONACION DOS');
        $form = [
            'submission_key' => (string) Str::uuid(),
            'category' => 'donations',
            'description' => 'Donación del mercadillo',
        ];

        $this->actingAs($this->treasury())->post("/bank/movements/{$first->id}/incomes", $form)
            ->assertSessionHasNoErrors();

        $this->actingAs($this->treasury())->post("/bank/movements/{$second->id}/incomes", $form)
            ->assertSessionHasErrors('submission_key')
            ->assertSessionMissing('status');

        $this->assertDatabaseCount('other_incomes', 1);
        $this->assertDatabaseMissing('other_incomes', ['bank_movement_id' => $second->id]);
    }

    /** S5: replaying a form whose classification was voided is refused, not silently repeated. */
    public function test_resending_the_form_after_voiding_is_refused_and_changes_nothing(): void
    {
        $movement = $this->movement(1500);
        $form = [
            'submission_key' => (string) Str::uuid(),
            'category' => 'grants',
            'description' => 'Subvención municipal',
        ];

        $this->actingAs($this->treasury())->post("/bank/movements/{$movement->id}/incomes", $form)
            ->assertSessionHasNoErrors();

        $income = $this->income();
        $this->actingAs($this->treasury())
            ->post("/bank/incomes/{$income->id}/void", ['reason' => 'Se clasificó dos veces'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->treasury())->post("/bank/movements/{$movement->id}/incomes", $form)
            ->assertSessionHasErrors('submission_key')
            ->assertSessionMissing('status');

        $this->from('/bank')->followingRedirects()
            ->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/incomes", $form)
            ->assertOk()
            ->assertSee('anulaste esta clasificación');

        $this->assertDatabaseCount('other_incomes', 1);
        $this->assertSame('Se clasificó dos veces', $income->fresh()->void_reason);
        $this->assertNotNull($income->fresh()->voided_at);
    }

    /** S5: a classified credit cannot be reconciled to dues through the HTTP path. */
    public function test_a_classified_credit_cannot_be_reconciled_to_dues(): void
    {
        $year = $this->year(2026, 1500);
        $due = $this->dueFor($this->member(), $year);
        $movement = $this->movement(1500);
        $this->classify($movement->id)->assertSessionHasNoErrors();

        $this->actingAs($this->treasury())
            ->post("/bank/movements/{$movement->id}/reconcile", [
                'submission_key' => (string) Str::uuid(),
                'allocations' => [$due->id => '15'],
            ])
            ->assertSessionHasErrors('allocations')
            ->assertSessionMissing('status');

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0, $due->fresh()->paidCents());
        $this->assertSame(0, Due::whereKey($due->id)->firstOrFail()->paidCents());
    }

    /** S5: the shared payment action refuses the same money for a due. */
    public function test_the_payment_action_refuses_a_classified_credit(): void
    {
        $year = $this->year(2026, 1500);
        $due = $this->dueFor($this->member(), $year);
        $movement = $this->movement(1500);
        $this->classify($movement->id)->assertSessionHasNoErrors();

        try {
            app(RegisterPayment::class)->handle($due, 1500, '2026-02-14', Payment::BANK, null, 'income-guard-key', $movement->id);
            $this->fail('El abono clasificado se aceptó como pago de una cuota.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0, $due->fresh()->paidCents());
    }

    /** S5 and S7, in the database: a raw payment insert on a classified credit is aborted. */
    public function test_the_database_refuses_a_payment_on_a_classified_credit(): void
    {
        $year = $this->year(2026, 1500);
        $due = $this->dueFor($this->member(), $year);
        $movement = $this->movement(1500);
        $this->classify($movement->id)->assertSessionHasNoErrors();

        $this->expectException(QueryException::class);

        Payment::forceCreate([
            'due_id' => $due->id,
            'bank_movement_id' => $movement->id,
            'amount_cents' => 1500,
            'paid_on' => '2026-02-14',
            'method' => Payment::BANK,
            'submission_key' => (string) Str::uuid(),
        ]);
    }

    /** S5 and S7, in the database: the same money cannot be classified twice at once. */
    public function test_the_database_refuses_a_second_active_classification(): void
    {
        $movement = $this->movement(1500);
        $this->classify($movement->id)->assertSessionHasNoErrors();

        $this->expectException(QueryException::class);

        // Models the simultaneous submission the trigger and the partial index have to stop.
        OtherIncome::forceCreate([
            'bank_movement_id' => $movement->id,
            'user_id' => $this->treasury()->id,
            'category' => 'other',
            'description' => 'Envío simultáneo',
            'amount_cents' => 1500,
            'submission_key' => (string) Str::uuid(),
        ]);
    }

    /** S5 and S7, in the database: un-voiding a classification cannot coexist with payments. */
    public function test_the_database_refuses_un_voiding_a_classification_when_dues_were_paid(): void
    {
        $year = $this->year(2026, 1500);
        $due = $this->dueFor($this->member(), $year);
        $movement = $this->movement(1500);
        $this->classify($movement->id)->assertSessionHasNoErrors();
        $income = $this->income();

        $this->actingAs($this->treasury())
            ->post("/bank/incomes/{$income->id}/void", ['reason' => 'Vuelve a conciliarse'])
            ->assertSessionHasNoErrors();

        Payment::forceCreate([
            'due_id' => $due->id,
            'bank_movement_id' => $movement->id,
            'amount_cents' => 1500,
            'paid_on' => '2026-02-14',
            'method' => Payment::BANK,
            'submission_key' => (string) Str::uuid(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('other_incomes')->where('id', $income->id)->update(['voided_at' => null]);
    }

    /**
     * S5 and S7, concurrency: a payment that had already read the credit as free is refused by
     * SQLite while the classification is being written. The interleaving is deterministic.
     */
    public function test_a_payment_landing_while_the_credit_is_classified_is_refused(): void
    {
        $year = $this->year(2026, 1500);
        $due = $this->dueFor($this->member(), $year);
        $movement = $this->movement(1500);
        $refused = false;

        OtherIncome::created(function (OtherIncome $income) use (&$refused, $due, $movement): void {
            if ($refused || $income->bank_movement_id !== $movement->id) {
                return;
            }

            $refused = true;

            try {
                Payment::forceCreate([
                    'due_id' => $due->id,
                    'bank_movement_id' => $movement->id,
                    'amount_cents' => 1500,
                    'paid_on' => '2026-02-14',
                    'method' => Payment::BANK,
                    'submission_key' => (string) Str::uuid(),
                ]);
            } catch (QueryException $e) {
                $this->assertStringContainsString('payments_bank_movement_other_income', $e->getMessage());
            }
        });

        $this->classify($movement->id)->assertSessionHasNoErrors();

        $this->assertTrue($refused);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('other_incomes', 1);
        $this->assertSame(0, $due->fresh()->paidCents());
        $this->assertSame('pending', $due->fresh()->status());
    }

    /** S1 and S4: the movements screen offers classification only for complete, unassigned credits. */
    public function test_the_screen_offers_classification_only_for_complete_credits(): void
    {
        $year = $this->year(2026, 1000);
        $ana = $this->dueFor($this->member(), $year);
        $luis = $this->dueFor($this->member('luis@example.test', 'Luis ficticio'), $year);
        $free = $this->movement(2000, 'DONACION LIBRE');
        $partial = $this->movement(2000, 'TRANSFERENCIA CONJUNTA');
        $charge = $this->movement(-2000, 'RECIBO DEVUELTO');
        app(ReconcileMovement::class)->handle($partial, [$ana->id => 1000, $luis->id => 1000], (string) Str::uuid());
        app(VoidPayment::class)->handle(Payment::where('due_id', $ana->id)->firstOrFail(), 'Se repartió mal.');

        $this->actingAs($this->treasury())->get('/bank')
            ->assertOk()
            ->assertSee('Clasificar como otro ingreso')
            // The partial credit explains why it cannot be classified instead of offering the form.
            ->assertSee('Un abono con parte asignada a cuotas no se puede clasificar como otro ingreso.')
            ->assertDontSee("/bank/movements/{$charge->id}/incomes", false)
            ->assertDontSee("/bank/movements/{$partial->id}/incomes", false)
            ->assertSee("/bank/movements/{$free->id}/incomes", false);
    }

    /** S3: once classified, the credit shows its category, description and the void form. */
    public function test_the_screen_shows_the_classification_and_the_void_form(): void
    {
        $movement = $this->movement(1500);
        $this->classify($movement->id, ['category' => 'activities', 'description' => 'Concierto benéfico'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->treasury())->get('/bank')
            ->assertOk()
            ->assertSee('Clasificado como otro ingreso')
            ->assertSee('Actividades')
            ->assertSee('Concierto benéfico')
            ->assertSee('Anular la clasificación')
            ->assertDontSee("/bank/movements/{$movement->id}/reconcile", false)
            ->assertDontSee("/bank/movements/{$movement->id}/incomes", false);
    }
}
