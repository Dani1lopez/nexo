<?php

namespace Tests\Feature;

use App\Modules\Access\Models\User;
use App\Modules\Fees\Actions\GenerateDues;
use App\Modules\Fees\Actions\GrantExemption;
use App\Modules\Fees\Actions\SetFeeYear;
use App\Modules\Fees\Models\Due;
use App\Modules\Fees\Models\Exemption;
use App\Modules\Fees\Models\FeeYear;
use App\Modules\Members\Models\Member;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Tests\Support\AuthTestCase;

class FeesTest extends AuthTestCase
{
    private function member(string $email, string $joined = '2024-01-15', string $status = 'active', string $name = 'Socia ficticia'): Member
    {
        $member = Member::create(['name' => $name, 'email' => $email, 'joined_on' => $joined]);
        $member->forceFill(['status' => $status])->save();

        return $member;
    }

    private function year(int $year = 2026, int $cents = 3000): FeeYear
    {
        return app(SetFeeYear::class)->handle($year, $cents);
    }

    private function treasury(): User
    {
        return $this->account('tesoreria@example.test', 'treasury', 'treasury-secret-value');
    }

    private function board(): User
    {
        return $this->account('junta@example.test', 'board', 'board-secret-value');
    }

    private function dueFor(Member $member, FeeYear $year): ?Due
    {
        return Due::where('member_id', $member->id)->where('fee_year_id', $year->id)->first();
    }

    public function test_anonymous_visitors_cannot_reach_any_fee_route(): void
    {
        $this->member('a@example.test');
        $year = $this->year();
        $due = Due::firstOrFail();

        $this->get('/fees')->assertRedirect('/login');
        $this->get('/fees/create')->assertRedirect('/login');
        $this->post('/fees', [])->assertRedirect('/login');
        $this->get("/fees/{$year->id}")->assertRedirect('/login');
        $this->get("/fees/{$year->id}/edit")->assertRedirect('/login');
        $this->put("/fees/{$year->id}", [])->assertRedirect('/login');
        $this->post("/dues/{$due->id}/exemptions", [])->assertRedirect('/login');
        $this->post('/exemptions/1/revoke', [])->assertRedirect('/login');
    }

    public function test_board_can_consult_but_cannot_change_anything(): void
    {
        $member = $this->member('socia@example.test');
        $year = $this->year();
        $due = $this->dueFor($member, $year);
        $this->actingAs($this->board());

        $this->get('/fees')->assertOk()->assertSee('2026')->assertSee('30,00 €')->assertDontSee('Fijar cuota de un año');
        $this->get("/fees/{$year->id}")->assertOk()->assertSee('Socia ficticia')->assertDontSee('Conceder exención');
        $this->get('/fees/create')->assertForbidden();
        $this->post('/fees', ['year' => 2027, 'amount' => '10'])->assertForbidden();
        $this->get("/fees/{$year->id}/edit")->assertForbidden();
        $this->put("/fees/{$year->id}", ['amount' => '99'])->assertForbidden();
        $this->post("/dues/{$due->id}/exemptions", ['reason' => 'Motivo'])->assertForbidden();

        $this->assertDatabaseCount('fee_years', 1);
        $this->assertSame(3000, $year->fresh()->amount_cents);
        $this->assertDatabaseCount('exemptions', 0);

        $exemption = app(GrantExemption::class)->handle($due, 'Motivo previo');
        $this->post("/exemptions/{$exemption->id}/revoke", ['reason' => 'Intento'])->assertForbidden();
        $this->assertNull($exemption->fresh()->revoked_at);
    }

    public function test_unknown_roles_are_denied_the_whole_module(): void
    {
        $this->actingAs($this->account('otro@example.test', 'admin', 'unknown-secret-value'));

        $this->get('/fees')->assertForbidden();
        $this->get('/fees/create')->assertForbidden();
    }

    public function test_fixing_a_year_creates_dues_only_for_active_members_joined_by_year_end(): void
    {
        $eligible = $this->member('a@example.test', '2020-06-01');
        $joinedLastDay = $this->member('b@example.test', '2026-12-31');
        $joinedLater = $this->member('c@example.test', '2027-01-01');
        $inactive = $this->member('d@example.test', '2020-06-01', 'inactive');

        $this->actingAs($this->treasury())
            ->post('/fees', ['year' => 2026, 'amount' => '30,00'])
            ->assertRedirect();

        $year = FeeYear::firstWhere('year', 2026);
        $this->assertSame(3000, $year->amount_cents);
        $this->assertNotNull($this->dueFor($eligible, $year));
        $this->assertNotNull($this->dueFor($joinedLastDay, $year));
        $this->assertNull($this->dueFor($joinedLater, $year));
        $this->assertNull($this->dueFor($inactive, $year));
        $this->assertDatabaseCount('dues', 2);
    }

    public function test_amounts_are_parsed_to_cents_and_invalid_input_creates_nothing(): void
    {
        $this->actingAs($this->treasury());
        $this->member('a@example.test');

        $year = 2040;
        foreach (['30' => 3000, '30,5' => 3050, '30.50' => 3050, '0,01' => 1, ' 12,34 ' => 1234] as $input => $cents) {
            $year++;
            $this->post('/fees', ['year' => $year, 'amount' => (string) $input])->assertRedirect();
            $this->assertSame($cents, FeeYear::firstWhere('year', $year)->amount_cents, "amount [{$input}]");
        }

        $count = FeeYear::count();
        $invalid = [
            'zero' => [2090, '0'], 'zero decimals' => [2090, '0,00'], 'negative' => [2090, '-5'],
            'three decimals' => [2090, '10,123'], 'text' => [2090, 'abc'], 'empty' => [2090, ''],
            'huge' => [2090, '10000000'], 'year text' => ['abc', '10'], 'year too old' => [1999, '10'],
            'year too far' => [2101, '10'], 'empty year' => ['', '10'],
        ];
        foreach ($invalid as $label => [$year, $amount]) {
            $this->from('/fees/create')->post('/fees', ['year' => $year, 'amount' => $amount])
                ->assertRedirect('/fees/create')->assertSessionHasErrors();
            $this->assertSame($count, FeeYear::count(), "case [{$label}] must create nothing");
        }
    }

    public function test_a_year_cannot_be_fixed_twice(): void
    {
        $this->year(2026, 3000);
        $this->actingAs($this->treasury());

        $this->from('/fees/create')->post('/fees', ['year' => 2026, 'amount' => '50'])
            ->assertRedirect('/fees/create')->assertSessionHasErrors('year');
        $this->assertDatabaseCount('fee_years', 1);
        $this->assertSame(3000, FeeYear::first()->amount_cents);
    }

    public function test_generating_dues_twice_never_duplicates_them(): void
    {
        $this->member('a@example.test');
        $year = $this->year();

        app(GenerateDues::class)->forYear($year);
        app(GenerateDues::class)->forYear($year);

        $this->assertDatabaseCount('dues', 1);
    }

    public function test_a_member_registered_after_the_year_receives_the_matching_dues(): void
    {
        $y2024 = $this->year(2024, 2000);
        $y2025 = $this->year(2025, 2500);
        $y2026 = $this->year(2026, 3000);

        $this->actingAs($this->treasury())
            ->post('/members', ['name' => 'Nueva socia', 'email' => 'nueva@example.test', 'joined_on' => '2025-03-01'])
            ->assertRedirect('/members');

        $member = Member::firstWhere('email', 'nueva@example.test');
        $this->assertNull($this->dueFor($member, $y2024));
        $this->assertNotNull($this->dueFor($member, $y2025));
        $this->assertNotNull($this->dueFor($member, $y2026));
    }

    public function test_deactivating_keeps_dues_and_reactivating_never_duplicates_them(): void
    {
        $member = $this->member('a@example.test');
        $year = $this->year();
        $this->actingAs($this->treasury());

        $this->post("/members/{$member->id}/deactivate")->assertRedirect('/members');
        $this->assertNotNull($this->dueFor($member, $year));
        $this->assertDatabaseCount('dues', 1);

        $this->post("/members/{$member->id}/reactivate")->assertRedirect('/members');
        $this->assertDatabaseCount('dues', 1);

        $next = $this->year(2027, 3100);
        $this->post("/members/{$member->id}/deactivate");
        app(GenerateDues::class)->forYear($this->year(2028, 3200));
        $this->assertNull($this->dueFor($member, FeeYear::firstWhere('year', 2028)), 'inactive members get no new dues');
        $this->assertNotNull($this->dueFor($member, $next), 'dues assigned while active are kept');
    }

    public function test_correcting_the_join_date_adds_missing_dues_without_removing_any(): void
    {
        $member = $this->member('a@example.test', '2026-02-01');
        $y2025 = $this->year(2025, 2500);
        $y2026 = $this->year(2026, 3000);
        $this->assertNull($this->dueFor($member, $y2025));

        $this->actingAs($this->treasury())
            ->put("/members/{$member->id}", ['name' => 'Socia ficticia', 'email' => 'a@example.test', 'joined_on' => '2025-02-01'])
            ->assertRedirect('/members');
        $this->assertNotNull($this->dueFor($member, $y2025));
        $this->assertNotNull($this->dueFor($member, $y2026));

        $this->put("/members/{$member->id}", ['name' => 'Socia ficticia', 'email' => 'a@example.test', 'joined_on' => '2026-02-01']);
        $this->assertNotNull($this->dueFor($member, $y2025), 'moving the join date later never removes dues');
        $this->assertDatabaseCount('dues', 2);
    }

    public function test_changing_the_amount_updates_every_due_but_never_the_year(): void
    {
        $member = $this->member('a@example.test');
        $year = $this->year();
        $this->actingAs($this->treasury());

        $this->get("/fees/{$year->id}/edit")->assertOk()->assertSee('30,00');
        $this->put("/fees/{$year->id}", ['year' => 2030, 'amount' => '45,50'])->assertRedirect("/fees/{$year->id}");

        $this->assertSame(4550, $year->fresh()->amount_cents);
        $this->assertSame(2026, $year->fresh()->year, 'the year itself is immutable');
        $this->get("/fees/{$year->id}")->assertSee('45,50 €')->assertDontSee('30,00 €');

        $this->from("/fees/{$year->id}/edit")->put("/fees/{$year->id}", ['amount' => '0'])->assertSessionHasErrors('amount');
        $this->assertSame(4550, $year->fresh()->amount_cents);
        $this->assertNotNull($this->dueFor($member, $year));
    }

    public function test_granting_an_exemption_requires_a_reason_and_zeroes_the_payable_amount(): void
    {
        $member = $this->member('a@example.test');
        $year = $this->year();
        $due = $this->dueFor($member, $year);
        $this->actingAs($this->treasury());

        foreach (['', '   ', str_repeat('a', 501)] as $reason) {
            $this->from("/fees/{$year->id}")->post("/dues/{$due->id}/exemptions", ['reason' => $reason])
                ->assertRedirect("/fees/{$year->id}")->assertSessionHasErrors('reason');
        }
        $this->assertDatabaseCount('exemptions', 0);
        $this->get("/fees/{$year->id}")->assertSee('A pagar: 30,00 €');

        $this->post("/dues/{$due->id}/exemptions", ['reason' => '  Situación económica justificada  '])->assertRedirect("/fees/{$year->id}");
        $this->assertDatabaseCount('exemptions', 1);
        $this->assertSame('Situación económica justificada', Exemption::first()->reason);
        $this->get("/fees/{$year->id}")->assertSee('A pagar: 0,00 €')->assertSee('Situación económica justificada');

        $this->from("/fees/{$year->id}")->post("/dues/{$due->id}/exemptions", ['reason' => 'Otro motivo'])->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('exemptions', 1);
    }

    public function test_revoking_needs_a_reason_and_keeps_the_exemption_in_the_history(): void
    {
        $member = $this->member('a@example.test');
        $year = $this->year();
        $due = $this->dueFor($member, $year);
        $exemption = app(GrantExemption::class)->handle($due, 'Primer motivo');
        $this->actingAs($this->treasury());

        foreach (['', '   '] as $reason) {
            $this->from("/fees/{$year->id}")->post("/exemptions/{$exemption->id}/revoke", ['reason' => $reason])
                ->assertSessionHasErrors('reason');
        }
        $this->assertNull($exemption->fresh()->revoked_at);

        $this->post("/exemptions/{$exemption->id}/revoke", ['reason' => 'Ya no procede'])->assertRedirect("/fees/{$year->id}");
        $fresh = $exemption->fresh();
        $this->assertNotNull($fresh->revoked_at);
        $this->assertSame('Ya no procede', $fresh->revoked_reason);
        $this->assertSame('Primer motivo', $fresh->reason);
        $this->assertDatabaseCount('exemptions', 1);
        $this->get("/fees/{$year->id}")->assertSee('A pagar: 30,00 €')->assertSee('Primer motivo')->assertSee('Ya no procede');

        $this->from("/fees/{$year->id}")->post("/exemptions/{$exemption->id}/revoke", ['reason' => 'Otra vez'])->assertSessionHasErrors('reason');
        $this->assertSame('Ya no procede', $exemption->fresh()->revoked_reason);

        $this->post("/dues/{$due->id}/exemptions", ['reason' => 'Nueva exención'])->assertRedirect("/fees/{$year->id}");
        $this->assertDatabaseCount('exemptions', 2);
        $this->get("/fees/{$year->id}")->assertSee('A pagar: 0,00 €');
    }

    public function test_reasons_and_names_are_escaped(): void
    {
        $member = $this->member('a@example.test', '2024-01-15', 'active', '<b>Nombre</b>');
        $year = $this->year();
        app(GrantExemption::class)->handle($this->dueFor($member, $year), '<script>alert(1)</script>');

        $this->actingAs($this->board())->get("/fees/{$year->id}")
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<b>Nombre</b>', false);
    }

    public function test_unknown_resources_return_not_found(): void
    {
        $this->actingAs($this->treasury());

        $this->get('/fees/999')->assertNotFound();
        $this->post('/dues/999/exemptions', ['reason' => 'x'])->assertNotFound();
        $this->post('/exemptions/999/revoke', ['reason' => 'x'])->assertNotFound();
    }

    public function test_fee_mutations_are_protected_against_request_forgery(): void
    {
        $member = $this->member('a@example.test');
        $year = $this->year();
        $due = $this->dueFor($member, $year);
        $this->actingAs($this->treasury());
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        $this->post('/fees', ['year' => 2027, 'amount' => '10'])->assertStatus(419);
        $this->put("/fees/{$year->id}", ['amount' => '99'])->assertStatus(419);
        $this->post("/dues/{$due->id}/exemptions", ['reason' => 'x'])->assertStatus(419);
        $this->assertDatabaseCount('fee_years', 1);
        $this->assertDatabaseCount('exemptions', 0);
        $this->assertSame(3000, $year->fresh()->amount_cents);
    }

    public function test_the_year_list_and_forms_are_in_spanish_with_a_member_link(): void
    {
        $this->year();
        $this->actingAs($this->treasury());

        $this->get('/fees')->assertOk()->assertSee('Cuotas anuales')->assertSee('Fijar cuota de un año');
        $this->get('/fees/create')->assertOk()->assertSee('Año')->assertSee('Importe en euros');
        $this->get('/dashboard')->assertSee('Cuotas anuales');
    }
}
