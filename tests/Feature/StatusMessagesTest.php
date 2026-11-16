<?php

namespace Tests\Feature;

use App\Modules\Fees\Actions\SetFeeYear;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\FeeYear;
use App\Modules\Fees\Models\Payment;
use App\Modules\Members\Models\Member;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Str;
use Tests\Support\AuthTestCase;

/** Every action that redirects tells the person what happened, with the same words as its button. */
class StatusMessagesTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->actingAs($this->account('tesoreria@example.test', 'treasury', 'treasury-secret-value'));
    }

    private function member(string $email = 'socia@example.test'): Member
    {
        return Member::create(['name' => 'Socia ficticia', 'email' => $email, 'joined_on' => '2020-01-01']);
    }

    private function dueOf(Member $member): Due
    {
        app(SetFeeYear::class)->handle(2026, 3000);

        return Due::where('member_id', $member->id)->firstOrFail();
    }

    /** @return array<string, string> */
    private function payment(string $amount = '10'): array
    {
        return ['amount' => $amount, 'paid_on' => '2026-03-01', 'method' => 'bank', 'note' => '', 'submission_key' => (string) Str::uuid()];
    }

    public function test_members_actions_confirm_what_they_did(): void
    {
        $this->post('/members', ['name' => 'Nueva socia', 'email' => 'nueva@example.test', 'joined_on' => '2024-02-01'])
            ->assertRedirect('/members')->assertSessionHas('status', 'Socio creado.');

        $member = Member::firstWhere('email', 'nueva@example.test');

        $this->put("/members/{$member->id}", ['name' => 'Nueva socia', 'email' => 'nueva@example.test', 'joined_on' => '2024-03-01'])
            ->assertSessionHas('status', 'Cambios guardados.');
        $this->post("/members/{$member->id}/deactivate")->assertSessionHas('status', 'Socio dado de baja.');
        $this->post("/members/{$member->id}/reactivate")->assertSessionHas('status', 'Socio reactivado.');
    }

    public function test_fee_year_actions_confirm_what_they_did(): void
    {
        $this->post('/fees', ['year' => 2026, 'amount' => '30'])->assertSessionHas('status', 'Cuota fijada.');

        $year = FeeYear::firstWhere('year', 2026);

        $this->put("/fees/{$year->id}", ['amount' => '35'])->assertSessionHas('status', 'Importe guardado.');
    }

    public function test_payment_and_exemption_actions_confirm_what_they_did(): void
    {
        $due = $this->dueOf($this->member());

        $this->post("/dues/{$due->id}/payments", $this->payment())->assertSessionHas('status', 'Pago registrado.');
        $this->post('/payments/'.Payment::firstOrFail()->id.'/void', ['reason' => 'Error de importe'])->assertSessionHas('status', 'Pago anulado.');
        $this->post("/dues/{$due->id}/exemptions", ['reason' => 'Situación económica'])->assertSessionHas('status', 'Exención concedida.');
        $this->post('/exemptions/'.$due->exemptions()->firstOrFail()->id.'/revoke', ['reason' => 'Ya no aplica'])->assertSessionHas('status', 'Exención anulada.');
    }

    public function test_the_confirmation_is_shown_once_after_the_redirect(): void
    {
        $this->followingRedirects()
            ->post('/members', ['name' => 'Nueva socia', 'email' => 'nueva@example.test', 'joined_on' => '2024-02-01'])
            ->assertSee('Socio creado.');

        $this->get('/members')->assertDontSee('Socio creado.');
    }

    public function test_a_refused_action_shows_no_confirmation(): void
    {
        $due = $this->dueOf($this->member());

        $this->post("/dues/{$due->id}/payments", $this->payment('999'))->assertSessionMissing('status');
        $this->post('/members', ['name' => '', 'email' => 'no-es-un-correo', 'joined_on' => 'ayer'])->assertSessionMissing('status');
    }
}
