<?php

namespace Tests\Feature;

use App\Modules\Access\Models\User;
use App\Modules\Cash\Actions\RegisterOpeningBalance;
use App\Modules\Cash\Models\CashOpeningBalance;
use App\Modules\Fees\Actions\RegisterPayment;
use App\Modules\Fees\Actions\SetFeeYear;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\FeeYear;
use App\Modules\Members\Models\Member;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuthTestCase;

/**
 * Rule 11 of issue #62 (S1, S4): the cash has a one-time opening balance that is added to the cash fee
 * payments Nexo already registers. The base never includes those payments, so money is never counted
 * twice, and it is fixed once: the same amount repeated is harmless and another amount never overwrites it.
 */
class CashOpeningBalanceTest extends AuthTestCase
{
    private function member(string $email = 'ana@example.test', string $name = 'Ana ficticia'): Member
    {
        return Member::create(['name' => $name, 'email' => $email, 'joined_on' => '2024-01-15']);
    }

    private function year(int $year = 2026, int $cents = 3000): FeeYear
    {
        return app(SetFeeYear::class)->handle($year, $cents);
    }

    private function cashPayment(int $cents = 2500): void
    {
        $ana = $this->member();
        $year = $this->year(2026);
        $due = Due::where('member_id', $ana->id)->where('fee_year_id', $year->id)->firstOrFail();

        app(RegisterPayment::class)->handle($due, $cents, '2026-02-01', 'cash', null, 'key-cash-'.$cents);
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

    private function registerOpening(int $cents): void
    {
        app(RegisterOpeningBalance::class)->handle($cents, (int) $this->treasury()->id);
    }

    /**
     * Rule 11 (S1): treasury fixes the base from the cash page, where the screen warns that the cash fee
     * payments are already counted, so the amount must not include them.
     */
    public function test_treasury_fixes_the_opening_balance_from_the_cash_page(): void
    {
        $this->cashPayment(2500);

        $this->actingAs($this->treasury())->get('/caja')
            ->assertOk()
            ->assertSeeInOrder(['Base inicial', 'Sin fijar', 'Todavía no se ha fijado el saldo inicial'])
            ->assertSeeInOrder(['Cuotas en efectivo', '25,00 €'])
            ->assertSeeInOrder(['Saldo en caja', '25,00 €'])
            ->assertSee('si los incluyes en la base, contarían dos veces')
            ->assertSee('action="/caja/saldo-inicial"', false)
            ->assertSee('name="amount"', false);

        $this->actingAs($this->treasury())
            ->post('/caja/saldo-inicial', ['amount' => '80'])
            ->assertRedirect('/caja')
            ->assertSessionHas('status', 'Saldo inicial de caja fijado.');

        $this->assertDatabaseHas('cash_opening_balances', [
            'amount_cents' => 8000,
            'user_id' => $this->treasury()->id,
        ]);

        $this->actingAs($this->treasury())->get('/caja')
            ->assertOk()
            ->assertSeeInOrder(['Base inicial', '80,00 €', 'fijada una sola vez'])
            ->assertSee('tesoreria@example.test')
            ->assertDontSee('Todavía no se ha fijado el saldo inicial')
            ->assertDontSee('action="/caja/saldo-inicial"', false);
    }

    /**
     * Rule 12 (S1, S4): the cash balance is the base plus the active cash fee payments, and the bank
     * stays out of it. Both parts are shown, so nobody has to guess what the total is made of.
     */
    public function test_the_cash_balance_adds_the_base_to_the_cash_payments_and_leaves_the_bank_alone(): void
    {
        $this->cashPayment(2500);
        $this->registerOpening(8000);

        $this->actingAs($this->board())->get('/saldos')
            ->assertOk()
            ->assertSeeInOrder(['Saldo en caja', '105,00 €'])
            ->assertSeeInOrder(['Base inicial', '80,00 €'])
            ->assertSeeInOrder(['Cuotas en efectivo', '25,00 €'])
            ->assertSee('si los incluyera, contarían dos veces')
            ->assertDontSee('Saldo en el banco');
    }

    /**
     * Rule 13 (S1): zero is a fixed base, not a missing one. A page that said "sin fijar" after storing
     * zero would invite treasury to fix the base twice.
     */
    public function test_zero_is_a_fixed_base_and_not_the_same_as_not_set(): void
    {
        $this->actingAs($this->treasury())
            ->post('/caja/saldo-inicial', ['amount' => '0'])
            ->assertRedirect('/caja')
            ->assertSessionHas('status', 'Saldo inicial de caja fijado.');

        $this->assertDatabaseHas('cash_opening_balances', ['amount_cents' => 0]);

        $this->actingAs($this->treasury())->get('/caja')
            ->assertOk()
            ->assertDontSee('Todavía no se ha fijado el saldo inicial')
            ->assertSeeInOrder(['Base inicial', '0,00 €', 'fijada una sola vez']);
    }

    /**
     * Rule 14 (S1): a resent form is not a second base. The same amount again reports the same outcome
     * and leaves exactly one row, so a double submit or a reload cannot move the cash.
     */
    public function test_the_same_amount_sent_twice_keeps_one_base_and_changes_nothing(): void
    {
        $this->actingAs($this->treasury())->post('/caja/saldo-inicial', ['amount' => '80'])->assertRedirect('/caja');
        $this->actingAs($this->treasury())
            ->post('/caja/saldo-inicial', ['amount' => '80,00'])
            ->assertRedirect('/caja')
            ->assertSessionHas('status', 'El saldo inicial ya tenía ese importe: no se ha cambiado nada.')
            ->assertSessionHasNoErrors();

        $this->assertSame(1, CashOpeningBalance::query()->count());
        $this->assertSame(8000, (int) CashOpeningBalance::query()->value('amount_cents'));
    }

    /**
     * Rule 15 (S1): a different amount never overwrites the fixed base. The refusal names the stored
     * amount and the stored row and the cash figure stay exactly as they were.
     */
    public function test_a_different_amount_never_overwrites_the_fixed_base(): void
    {
        $this->cashPayment(2500);
        $this->registerOpening(8000);

        $this->actingAs($this->treasury())
            ->post('/caja/saldo-inicial', ['amount' => '90'])
            ->assertRedirect()
            ->assertSessionHasErrors([
                'amount' => 'El saldo inicial ya está fijado en 80,00 € y no se sobrescribe. Es una base única que no se recalcula.',
            ]);

        $this->assertSame(8000, (int) CashOpeningBalance::query()->value('amount_cents'));

        $this->actingAs($this->board())->get('/saldos')
            ->assertOk()
            ->assertSeeInOrder(['Saldo en caja', '105,00 €'])
            ->assertSeeInOrder(['Base inicial', '80,00 €']);
    }

    /**
     * Rule 16 (S1): the database holds the single row too. Two simultaneous first submissions can pass
     * the read in code, so the unique index is what lets exactly one base in.
     */
    public function test_the_database_rejects_a_second_opening_balance_row(): void
    {
        $this->registerOpening(8000);
        $treasury = $this->treasury();

        $this->expectException(QueryException::class);
        DB::table('cash_opening_balances')->insert([
            'user_id' => $treasury->id,
            'amount_cents' => 8000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Rule 17 (S1): a negative base, a text or an empty amount is refused without storing anything.
     */
    public function test_the_opening_balance_refuses_amounts_that_are_not_euros(): void
    {
        foreach (['-5', 'abc', '', '10,555'] as $amount) {
            $this->actingAs($this->treasury())
                ->post('/caja/saldo-inicial', ['amount' => $amount])
                ->assertSessionHasErrors('amount');
        }

        $this->assertSame(0, CashOpeningBalance::query()->count());
    }

    /**
     * Rule 18 (S3): treasury writes the base and the row records who did it; the board only consults.
     */
    public function test_only_treasury_writes_the_base_and_the_board_only_consults(): void
    {
        $this->actingAs($this->board())->get('/caja')
            ->assertOk()
            ->assertSee('Todavía no se ha fijado el saldo inicial')
            ->assertDontSee('action="/caja/saldo-inicial"', false)
            ->assertDontSee('name="amount"', false);

        $this->actingAs($this->board())
            ->post('/caja/saldo-inicial', ['amount' => '80'])
            ->assertForbidden();

        $this->assertSame(0, CashOpeningBalance::query()->count());

        $this->registerOpening(8000);

        $this->actingAs($this->board())->get('/caja')
            ->assertOk()
            ->assertSeeInOrder(['Base inicial', '80,00 €'])
            ->assertDontSee('action="/caja/saldo-inicial"', false);
    }

    /**
     * Preserve (#62, #44): without a base the cash figure is still the one the previous delivery
     * showed, the fee payments alone, and only the new base line says it is not fixed yet.
     */
    public function test_the_cash_summary_still_works_without_an_opening_balance(): void
    {
        $this->cashPayment(3000);

        $this->actingAs($this->board())->get('/saldos')
            ->assertOk()
            ->assertSeeInOrder(['Saldo en caja', '30,00 €', '1 pago de cuota en efectivo'])
            ->assertSeeInOrder(['Base inicial', 'Sin fijar'])
            ->assertSeeInOrder(['Cuotas en efectivo', '30,00 €', '1 pago de cuota en efectivo']);
    }
}
