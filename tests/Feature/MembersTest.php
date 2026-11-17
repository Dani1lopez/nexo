<?php

namespace Tests\Feature;

use App\Modules\Access\Models\User;
use App\Modules\Members\Models\Member;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Tests\Support\AuthTestCase;

class MembersTest extends AuthTestCase
{
    private function member(string $email = 'socia@example.test', string $status = 'active', string $name = 'Socia ficticia'): Member
    {
        $member = Member::create(['name' => $name, 'email' => $email, 'joined_on' => '2024-01-15']);
        $member->forceFill(['status' => $status])->save();

        return $member;
    }

    private function treasury(): User
    {
        return $this->account('tesoreria@example.test', 'treasury', 'treasury-secret-value');
    }

    private function board(): User
    {
        return $this->account('junta@example.test', 'board', 'board-secret-value');
    }

    public function test_anonymous_visitors_cannot_reach_any_member_route(): void
    {
        $member = $this->member();

        $this->get('/members')->assertRedirect('/login');
        $this->get('/members/create')->assertRedirect('/login');
        $this->post('/members', [])->assertRedirect('/login');
        $this->get("/members/{$member->id}/edit")->assertRedirect('/login');
        $this->put("/members/{$member->id}", [])->assertRedirect('/login');
        $this->post("/members/{$member->id}/deactivate")->assertRedirect('/login');
        $this->post("/members/{$member->id}/reactivate")->assertRedirect('/login');
        $this->assertSame('active', $member->fresh()->status);
    }

    public function test_board_can_view_but_cannot_change_members_and_nothing_is_modified(): void
    {
        $member = $this->member();
        $this->actingAs($this->board());

        $this->get('/members')->assertOk()->assertSee('Socia ficticia')->assertDontSee('Nuevo socio');
        $this->get('/members/create')->assertForbidden();
        $this->post('/members', ['name' => 'Otra', 'email' => 'otra@example.test', 'joined_on' => '2024-02-01'])->assertForbidden();
        $this->get("/members/{$member->id}/edit")->assertForbidden();
        $this->put("/members/{$member->id}", ['name' => 'Cambiado', 'email' => 'x@example.test', 'joined_on' => '2024-02-01'])->assertForbidden();
        $this->post("/members/{$member->id}/deactivate")->assertForbidden();

        $this->assertDatabaseCount('members', 1);
        $this->assertSame('Socia ficticia', $member->fresh()->name);
        $this->assertSame('active', $member->fresh()->status);

        $inactive = $this->member('baja@example.test', 'inactive');
        $this->post("/members/{$inactive->id}/reactivate")->assertForbidden();
        $this->assertSame('inactive', $inactive->fresh()->status);
    }

    public function test_users_with_an_unknown_role_are_denied_the_whole_module(): void
    {
        $this->actingAs($this->accountWithStoredRole('otro@example.test', 'admin', 'unknown-secret-value'));

        $this->get('/members')->assertForbidden();
        $this->get('/members/create')->assertForbidden();
    }

    public function test_treasury_creates_a_member_with_normalized_email_and_active_status(): void
    {
        $this->actingAs($this->treasury())
            ->post('/members', [
                'name' => '  Ana Pérez ficticia ',
                'email' => '  ANA@Example.TEST ',
                'joined_on' => '2023-09-01',
                'status' => 'inactive',
                'id' => 99,
            ])
            ->assertRedirect('/members');

        $this->assertDatabaseCount('members', 1);
        $member = Member::first();
        $this->assertSame('Ana Pérez ficticia', $member->name);
        $this->assertSame('ana@example.test', $member->email);
        $this->assertSame('2023-09-01', $member->joined_on->toDateString());
        $this->assertSame('active', $member->status, 'status cannot be forged through the create form');
        $this->assertNotSame(99, $member->id);
    }

    public function test_create_form_defaults_the_join_date_to_today_and_is_in_spanish(): void
    {
        $this->travelTo('2026-03-04 10:00:00');

        $this->actingAs($this->treasury())->get('/members/create')
            ->assertOk()
            ->assertSee('value="2026-03-04"', false)
            ->assertSee('Nombre completo')
            ->assertSee('Fecha de alta');
    }

    public function test_invalid_member_input_creates_nothing_and_keeps_the_typed_values(): void
    {
        $this->actingAs($this->treasury());
        $this->member('existente@example.test');

        $cases = [
            'empty name' => ['', 'nuevo@example.test', '2024-01-01', 'name'],
            'long name' => [str_repeat('a', 256), 'nuevo@example.test', '2024-01-01', 'name'],
            'invalid email' => ['Nuevo', 'no-es-correo', '2024-01-01', 'email'],
            'non .test email' => ['Nuevo', 'nuevo@example.com', '2024-01-01', 'email'],
            'duplicate email' => ['Nuevo', 'existente@example.test', '2024-01-01', 'email'],
            'duplicate email in other case' => ['Nuevo', 'EXISTENTE@example.test', '2024-01-01', 'email'],
            'missing date' => ['Nuevo', 'nuevo@example.test', '', 'joined_on'],
            'invalid date' => ['Nuevo', 'nuevo@example.test', '2024-13-45', 'joined_on'],
        ];

        foreach ($cases as $label => [$name, $email, $date, $field]) {
            $this->from('/members/create')
                ->post('/members', ['name' => $name, 'email' => $email, 'joined_on' => $date])
                ->assertRedirect('/members/create')
                ->assertSessionHasErrors($field);
            $this->assertDatabaseCount('members', 1);
        }

        $this->from('/members/create')
            ->post('/members', ['name' => 'Nuevo', 'email' => 'nuevo@example.com', 'joined_on' => '2024-01-01']);
        $this->assertSame('Nuevo', session('_old_input.name'));
    }

    public function test_treasury_edits_a_member_and_may_keep_its_own_email(): void
    {
        $member = $this->member();
        $this->actingAs($this->treasury());

        $this->get("/members/{$member->id}/edit")->assertOk()->assertSee('Socia ficticia');
        $this->put("/members/{$member->id}", [
            'name' => 'Socia renombrada',
            'email' => 'socia@example.test',
            'joined_on' => '2022-05-06',
            'status' => 'inactive',
        ])->assertRedirect('/members');

        $fresh = $member->fresh();
        $this->assertSame('Socia renombrada', $fresh->name);
        $this->assertSame('socia@example.test', $fresh->email);
        $this->assertSame('2022-05-06', $fresh->joined_on->toDateString());
        $this->assertSame('active', $fresh->status, 'status changes only through deactivate and reactivate');
    }

    public function test_editing_cannot_take_the_email_of_another_member(): void
    {
        $member = $this->member();
        $this->member('otro@example.test');
        $this->actingAs($this->treasury());

        $this->from("/members/{$member->id}/edit")
            ->put("/members/{$member->id}", ['name' => 'X', 'email' => 'OTRO@example.test', 'joined_on' => '2024-01-01'])
            ->assertSessionHasErrors('email');
        $this->assertSame('socia@example.test', $member->fresh()->email);
    }

    public function test_deactivating_keeps_the_record_and_treasury_can_reactivate(): void
    {
        $member = $this->member();
        $this->actingAs($this->treasury());

        $this->post("/members/{$member->id}/deactivate")->assertRedirect('/members');
        $this->assertDatabaseCount('members', 1);
        $this->assertSame('inactive', $member->fresh()->status);
        $this->assertSame('Socia ficticia', $member->fresh()->name);

        $this->post("/members/{$member->id}/reactivate")->assertRedirect('/members');
        $this->assertSame('active', $member->fresh()->status);
    }

    public function test_unknown_members_return_not_found(): void
    {
        $this->actingAs($this->treasury());

        $this->get('/members/999/edit')->assertNotFound();
        $this->post('/members/999/deactivate')->assertNotFound();
    }

    public function test_list_shows_active_members_by_default_with_a_status_filter(): void
    {
        $this->member('activa@example.test', 'active', 'Socia activa');
        $this->member('baja@example.test', 'inactive', 'Socia de baja');
        $this->actingAs($this->board());

        $this->get('/members')->assertOk()->assertSee('Socia activa')->assertDontSee('Socia de baja');
        $this->get('/members?estado=activos')->assertSee('Socia activa')->assertDontSee('Socia de baja');
        $this->get('/members?estado=bajas')->assertSee('Socia de baja')->assertDontSee('Socia activa');
        $this->get('/members?estado=todos')->assertSee('Socia activa')->assertSee('Socia de baja');
        $this->get('/members?estado=desconocido')->assertOk()->assertSee('Socia activa')->assertDontSee('Socia de baja');
    }

    public function test_member_names_are_escaped_in_the_list(): void
    {
        $this->member('xss@example.test', 'active', '<script>alert(1)</script>');
        $this->actingAs($this->board())->get('/members')->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_member_mutations_are_protected_against_request_forgery(): void
    {
        $member = $this->member();
        $this->actingAs($this->treasury());
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        $this->post('/members', ['name' => 'X', 'email' => 'x@example.test', 'joined_on' => '2024-01-01'])->assertStatus(419);
        $this->put("/members/{$member->id}", ['name' => 'X', 'email' => 'x@example.test', 'joined_on' => '2024-01-01'])->assertStatus(419);
        $this->post("/members/{$member->id}/deactivate")->assertStatus(419);
        $this->assertDatabaseCount('members', 1);
        $this->assertSame('active', $member->fresh()->status);
    }
}
