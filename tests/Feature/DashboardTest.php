<?php

namespace Tests\Feature;

use App\Modules\Access\Models\User;
use App\Modules\Fees\Actions\RegisterPayment;
use App\Modules\Fees\Actions\SetFeeYear;
use App\Modules\Fees\Models\Due;
use App\Modules\Members\Models\Member;
use Tests\Support\AuthTestCase;

class DashboardTest extends AuthTestCase
{
    private function member(string $email, string $status = 'active'): Member
    {
        $member = Member::create(['name' => 'Socia '.$email, 'email' => $email, 'joined_on' => '2024-01-15']);
        $member->forceFill(['status' => $status])->save();

        return $member;
    }

    private function board(): User
    {
        return $this->account('junta@example.test', 'board', 'board-secret-value');
    }

    public function test_the_overview_without_fee_years_says_so_and_still_counts_active_members(): void
    {
        $this->member('a@example.test');
        $this->member('b@example.test', 'inactive');

        $this->actingAs($this->board())->get('/dashboard')
            ->assertOk()
            ->assertSee('Todavía no hay cuotas fijadas')
            ->assertSee('Hay 1 socio activo')
            ->assertSee('Todavía no se ha importado ningún extracto bancario.');
    }

    public function test_the_overview_sums_what_is_payable_paid_and_pending_for_the_latest_year(): void
    {
        $this->member('a@example.test');
        $this->member('b@example.test');
        $this->member('inactive@example.test', 'inactive');
        app(SetFeeYear::class)->handle(2025, 1000);
        $latest = app(SetFeeYear::class)->handle(2026, 3000);
        app(RegisterPayment::class)->handle(Due::where('fee_year_id', $latest->id)->orderBy('id')->firstOrFail(), 1000, '2026-01-10', 'bank', null, 'key-1');

        $this->actingAs($this->board())->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['Socios activos', '2'])
            ->assertSee('Pendiente de cobro en 2026')
            ->assertSee('50,00 €')
            ->assertSee('2 cuotas pendientes')
            ->assertSee('Cobrado en 2026')
            ->assertSee('10,00 €')
            ->assertSee('de 60,00 € a cobrar');
    }

    public function test_anonymous_visitors_are_sent_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
