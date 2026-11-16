<?php

namespace Tests\Feature;

use App\Modules\Access\Models\User;
use App\Modules\Fees\Actions\GrantExemption;
use App\Modules\Fees\Actions\SetFeeYear;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\FeeYear;
use App\Modules\Fees\Models\Payment;
use App\Modules\Members\Models\Member;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Tests\Support\AuthTestCase;

class PaymentsTest extends AuthTestCase
{
    private function setUpDue(int $cents = 3000, int $yearNumber = 2026): Due
    {
        $member = Member::create(['name' => 'Socia ficticia', 'email' => 'socia'.$yearNumber.'@example.test', 'joined_on' => '2020-01-01']);
        $year = FeeYear::firstWhere('year', $yearNumber) ?? app(SetFeeYear::class)->handle($yearNumber, $cents);

        return Due::where('member_id', $member->id)->where('fee_year_id', $year->id)->firstOrFail();
    }

    private function treasury(): User
    {
        return $this->account('tesoreria@example.test', 'treasury', 'treasury-secret-value');
    }

    private function board(): User
    {
        return $this->account('junta@example.test', 'board', 'board-secret-value');
    }

    /** @return array<string, string> */
    private function payment(string $amount = '10', array $overrides = []): array
    {
        return array_merge(['amount' => $amount, 'paid_on' => '2026-03-01', 'method' => 'bank', 'note' => ''], $overrides);
    }

    private function pay(Due $due, string $amount = '10', array $overrides = []): void
    {
        $this->post("/dues/{$due->id}/payments", $this->payment($amount, $overrides))->assertRedirect("/fees/{$due->fee_year_id}");
    }

    public function test_anonymous_visitors_cannot_register_or_void_payments(): void
    {
        $due = $this->setUpDue();

        $this->post("/dues/{$due->id}/payments", $this->payment())->assertRedirect('/login');
        $this->post('/payments/1/void', ['reason' => 'x'])->assertRedirect('/login');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_board_consults_payments_but_cannot_change_them(): void
    {
        $due = $this->setUpDue();
        $this->actingAs($this->treasury());
        $this->pay($due, '10');
        $payment = Payment::firstOrFail();

        $this->actingAs($this->board());
        $this->get("/fees/{$due->fee_year_id}")->assertOk()
            ->assertSee('Pagado: 10,00 €')->assertSee('Pendiente: 20,00 €')->assertSee('Parcial')
            ->assertDontSee('Registrar pago')->assertDontSee('Anular pago');
        $this->post("/dues/{$due->id}/payments", $this->payment('5'))->assertForbidden();
        $this->post("/payments/{$payment->id}/void", ['reason' => 'Intento'])->assertForbidden();

        $this->assertDatabaseCount('payments', 1);
        $this->assertNull($payment->fresh()->voided_at);
    }

    public function test_partial_payments_add_up_until_the_due_is_fully_paid(): void
    {
        $due = $this->setUpDue(3000);
        $this->actingAs($this->treasury());

        $this->get("/fees/{$due->fee_year_id}")->assertSee('Pendiente de pago')->assertSee('Pendiente: 30,00 €');

        $this->pay($due, '12,50', ['paid_on' => '2026-02-10', 'method' => 'cash', 'note' => '  Entregado en mano  ']);
        $payment = Payment::firstOrFail();
        $this->assertSame(1250, $payment->amount_cents);
        $this->assertSame('2026-02-10', $payment->paid_on->toDateString());
        $this->assertSame('cash', $payment->method);
        $this->assertSame('Entregado en mano', $payment->note);
        $this->get("/fees/{$due->fee_year_id}")->assertSee('Pagado: 12,50 €')->assertSee('Pendiente: 17,50 €')->assertSee('Parcial')->assertSee('Efectivo');

        $this->pay($due, '17,50');
        $this->get("/fees/{$due->fee_year_id}")->assertSee('Pagado: 30,00 €')->assertSee('Pendiente: 0,00 €')->assertSee('Pagada')->assertSee('Banco');

        $this->from("/fees/{$due->fee_year_id}")->post("/dues/{$due->id}/payments", $this->payment('0,01'))->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_invalid_payments_are_rejected_and_store_nothing(): void
    {
        $this->travelTo('2026-06-10 12:00:00');
        $due = $this->setUpDue(3000);
        $this->actingAs($this->treasury());

        $invalid = [
            'zero' => ['0', []], 'negative' => ['-5', []], 'three decimals' => ['1,123', []], 'text' => ['abc', []],
            'empty amount' => ['', []], 'over the pending amount' => ['30,01', []],
            'future date' => ['5', ['paid_on' => '2026-06-11']], 'impossible date' => ['5', ['paid_on' => '2026-02-31']],
            'empty date' => ['5', ['paid_on' => '']], 'bad method' => ['5', ['method' => 'bizum']], 'empty method' => ['5', ['method' => '']],
            'long note' => ['5', ['note' => str_repeat('a', 256)]],
        ];
        foreach ($invalid as $label => [$amount, $overrides]) {
            $this->from("/fees/{$due->fee_year_id}")->post("/dues/{$due->id}/payments", $this->payment($amount, $overrides))
                ->assertRedirect("/fees/{$due->fee_year_id}")->assertSessionHasErrors();
            $this->assertSame(0, Payment::count(), "case [{$label}] must store nothing");
        }

        $this->post("/dues/{$due->id}/payments", $this->payment('5', ['paid_on' => '2026-06-10']))->assertRedirect();
        $this->post("/dues/{$due->id}/payments", $this->payment('5', ['paid_on' => '1999-01-01']))->assertRedirect();
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_payments_that_together_exceed_the_pending_amount_are_rejected(): void
    {
        $due = $this->setUpDue(3000);
        $this->actingAs($this->treasury());

        $this->pay($due, '20');
        $this->from("/fees/{$due->fee_year_id}")->post("/dues/{$due->id}/payments", $this->payment('15'))->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('payments', 1);
        $this->pay($due, '10');
    }

    public function test_an_exempt_due_accepts_no_payments(): void
    {
        $due = $this->setUpDue();
        app(GrantExemption::class)->handle($due, 'Motivo');
        $this->actingAs($this->treasury());

        $this->from("/fees/{$due->fee_year_id}")->post("/dues/{$due->id}/payments", $this->payment('1'))->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('payments', 0);
        $this->get("/fees/{$due->fee_year_id}")->assertSee('Exenta');
    }

    public function test_voiding_needs_a_reason_keeps_the_payment_and_frees_the_amount(): void
    {
        $due = $this->setUpDue(3000);
        $this->actingAs($this->treasury());
        $this->pay($due, '30');
        $payment = Payment::firstOrFail();

        foreach (['', '   ', str_repeat('a', 501)] as $reason) {
            $this->from("/fees/{$due->fee_year_id}")->post("/payments/{$payment->id}/void", ['reason' => $reason])->assertSessionHasErrors('reason');
        }
        $this->assertNull($payment->fresh()->voided_at);

        $this->post("/payments/{$payment->id}/void", ['reason' => '  Importe mal tecleado '])->assertRedirect("/fees/{$due->fee_year_id}");
        $fresh = $payment->fresh();
        $this->assertNotNull($fresh->voided_at);
        $this->assertSame('Importe mal tecleado', $fresh->void_reason);
        $this->assertSame(3000, $fresh->amount_cents);
        $this->assertDatabaseCount('payments', 1);
        $this->get("/fees/{$due->fee_year_id}")->assertSee('Pendiente: 30,00 €')->assertSee('Anulado')->assertSee('Importe mal tecleado');

        $this->from("/fees/{$due->fee_year_id}")->post("/payments/{$payment->id}/void", ['reason' => 'Otra vez'])->assertSessionHasErrors('reason');
        $this->assertSame('Importe mal tecleado', $payment->fresh()->void_reason);

        $this->pay($due, '30');
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_an_exemption_needs_the_active_payments_to_be_voided_first(): void
    {
        $due = $this->setUpDue();
        $this->actingAs($this->treasury());
        $this->pay($due, '10');
        $payment = Payment::firstOrFail();

        $this->from("/fees/{$due->fee_year_id}")->post("/dues/{$due->id}/exemptions", ['reason' => 'Motivo'])->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('exemptions', 0);

        $this->post("/payments/{$payment->id}/void", ['reason' => 'Error']);
        $this->post("/dues/{$due->id}/exemptions", ['reason' => 'Motivo'])->assertRedirect("/fees/{$due->fee_year_id}");
        $this->assertDatabaseCount('exemptions', 1);
    }

    public function test_the_year_amount_is_locked_while_any_payment_is_active(): void
    {
        $due = $this->setUpDue(3000);
        $other = $this->setUpDue(4000, 2027);
        $this->actingAs($this->treasury());
        $this->pay($due, '10');
        $payment = Payment::firstOrFail();

        $this->get("/fees/{$due->fee_year_id}/edit")->assertOk()->assertSee('pagos vigentes');
        $this->from("/fees/{$due->fee_year_id}/edit")->put("/fees/{$due->fee_year_id}", ['amount' => '99'])->assertSessionHasErrors('amount');
        $this->assertSame(3000, $due->feeYear->fresh()->amount_cents);

        $this->put("/fees/{$other->fee_year_id}", ['amount' => '45'])->assertRedirect();
        $this->assertSame(4500, $other->feeYear->fresh()->amount_cents, 'other years are not locked');

        $this->post("/payments/{$payment->id}/void", ['reason' => 'Error']);
        $this->put("/fees/{$due->fee_year_id}", ['amount' => '99'])->assertRedirect("/fees/{$due->fee_year_id}");
        $this->assertSame(9900, $due->feeYear->fresh()->amount_cents);
    }

    public function test_reactivating_a_member_never_generates_dues_for_years_fixed_while_inactive(): void
    {
        $member = Member::create(['name' => 'Socia', 'email' => 'socia@example.test', 'joined_on' => '2020-01-01']);
        $this->actingAs($this->treasury());

        $this->post("/members/{$member->id}/deactivate");
        app(SetFeeYear::class)->handle(2026, 3000);
        $this->post("/members/{$member->id}/reactivate");
        $this->assertSame(0, Due::where('member_id', $member->id)->count());

        app(SetFeeYear::class)->handle(2027, 3000);
        $this->assertSame(1, Due::where('member_id', $member->id)->count());
    }

    public function test_notes_and_reasons_are_escaped_and_the_form_warns_about_personal_data(): void
    {
        $due = $this->setUpDue();
        $this->actingAs($this->treasury());
        $this->pay($due, '10', ['note' => '<script>alert(1)</script>']);
        $this->post('/payments/'.Payment::firstOrFail()->id.'/void', ['reason' => '<b>motivo</b>']);

        $this->get("/fees/{$due->fee_year_id}")->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<b>motivo</b>', false)
            ->assertSee('No escribas datos personales reales');
    }

    public function test_unknown_resources_return_not_found(): void
    {
        $this->actingAs($this->treasury());

        $this->post('/dues/999/payments', $this->payment())->assertNotFound();
        $this->post('/payments/999/void', ['reason' => 'x'])->assertNotFound();
    }

    public function test_payment_mutations_are_protected_against_request_forgery(): void
    {
        $due = $this->setUpDue();
        $this->actingAs($this->treasury());
        $this->pay($due, '10');
        $payment = Payment::firstOrFail();
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        $this->post("/dues/{$due->id}/payments", $this->payment('5'))->assertStatus(419);
        $this->post("/payments/{$payment->id}/void", ['reason' => 'x'])->assertStatus(419);
        $this->assertDatabaseCount('payments', 1);
        $this->assertNull($payment->fresh()->voided_at);
    }
}
