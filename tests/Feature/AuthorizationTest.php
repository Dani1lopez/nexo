<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Gate;
use Tests\Support\AuthTestCase;

class AuthorizationTest extends AuthTestCase
{
    public function test_role_gates_fail_closed_and_panel_shows_individual_identity(): void
    {
        $treasury = $this->account('tesoreria@example.test', 'treasury', 'password-one', 'Ana ficticia');
        $board = $this->account('junta@example.test', 'board', 'password-two', 'Luis ficticio');
        $unknown = $this->accountWithStoredRole('otro@example.test', 'admin', 'password-three');

        $this->assertTrue(Gate::forUser($treasury)->allows('view-treasury'));
        $this->assertTrue(Gate::forUser($treasury)->allows('edit-treasury'));
        $this->assertTrue(Gate::forUser($board)->allows('view-treasury'));
        $this->assertFalse(Gate::forUser($board)->allows('edit-treasury'));
        $this->assertFalse(Gate::forUser($unknown)->allows('view-treasury'));
        $this->assertFalse(Gate::forUser($unknown)->allows('edit-treasury'));
        $this->actingAs($board)->get('/dashboard')->assertOk()->assertSee('Luis ficticio')->assertDontSee('Ana ficticia');
        $this->actingAs($unknown)->get('/dashboard')->assertForbidden();
    }
}
