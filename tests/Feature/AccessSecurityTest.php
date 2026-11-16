<?php

namespace Tests\Feature;

use App\Modules\Access\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Support\AuthTestCase;

class AccessSecurityTest extends AuthTestCase
{
    public function test_forged_role_input_on_login_does_not_change_stored_role_or_grant_edit(): void
    {
        $board = $this->account('junta@example.test', 'board', 'board-secret-value');

        $this->attemptLogin('junta@example.test', 'board-secret-value', ['role' => 'treasury'])->assertRedirect('/dashboard');

        $this->assertSame('board', $board->fresh()->role);
        $this->assertFalse(Gate::forUser($board->fresh())->allows('edit-treasury'));
        $this->get('/dashboard')->assertOk()->assertSee('Junta');
    }

    public function test_unknown_and_case_variant_roles_are_denied(): void
    {
        foreach (['', 'Treasury', 'BOARD', 'treasury '] as $i => $role) {
            $user = $this->account("rol{$i}@example.test", $role, 'unknown-secret-value');
            $this->assertFalse(Gate::forUser($user)->allows('view-treasury'), "role [{$role}] must not view");
            $this->assertFalse(Gate::forUser($user)->allows('edit-treasury'), "role [{$role}] must not edit");
            $this->actingAs($user)->get('/dashboard')->assertForbidden();
        }
    }

    public function test_login_rotates_session_and_csrf_token_and_logout_ends_access(): void
    {
        $this->account('ana@example.test', 'treasury', 'treasury-secret-value');
        $this->get('/login')->assertOk();
        $guestSession = session()->getId();
        $guestToken = session()->token();

        $this->attemptLogin('ana@example.test', 'treasury-secret-value')->assertRedirect('/dashboard');
        $this->assertNotSame($guestSession, session()->getId());
        $this->assertNotSame($guestToken, session()->token());
        $this->assertAuthenticated();

        $authSession = session()->getId();
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertNotSame($authSession, session()->getId());
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_failed_login_errors_are_generic_and_keep_only_email(): void
    {
        $this->account('ana@example.test', 'board', 'correct-secret-value');

        $generic = ['email' => 'Las credenciales no son válidas.'];

        $this->from('/login')->attemptLogin('ana@example.test', 'wrong-secret-value')
            ->assertRedirect('/login')->assertSessionHasErrors($generic);
        $this->assertSame('ana@example.test', session('_old_input.email'));
        $this->assertNull(session('_old_input.'.'pass'.'word'));

        $this->from('/login')->attemptLogin('nadie@example.test', 'wrong-secret-value')
            ->assertRedirect('/login')->assertSessionHasErrors($generic);
    }

    public function test_rate_limit_is_isolated_per_ip_and_per_email_and_lasts_the_full_window(): void
    {
        $this->account('ana@example.test', 'board', 'correct-secret-value');
        $this->account('luis@example.test', 'board', 'other-secret-value');
        for ($i = 0; $i < 5; $i++) {
            $this->attemptLogin('ana@example.test', 'wrong-secret-value', ip: '203.0.113.10');
        }

        $this->attemptLogin('ana@example.test', 'correct-secret-value', ip: '203.0.113.10')
            ->assertSessionHasErrors(['email' => 'Demasiados intentos. Inténtalo de nuevo más tarde.']);
        $this->assertGuest();

        $this->attemptLogin('luis@example.test', 'other-secret-value', ip: '203.0.113.10')->assertRedirect('/dashboard');
        $this->post('/logout');

        $this->attemptLogin('ana@example.test', 'correct-secret-value', ip: '198.51.100.20')->assertRedirect('/dashboard');
        $this->post('/logout');

        $this->travel(59)->seconds();
        $this->attemptLogin('ana@example.test', 'correct-secret-value', ip: '203.0.113.10')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_rejected_demo_provisioning_creates_no_account_and_never_prints_the_secret(): void
    {
        $this->app['env'] = 'local';
        $cases = [
            'empty name' => ['', 'persona@example.test'],
            'long name' => [str_repeat('a', 256), 'persona@example.test'],
            'non .test email' => ['Persona', 'persona@example.com'],
            'invalid email' => ['Persona', 'no-es-correo'],
        ];
        foreach ($cases as $label => [$name, $email]) {
            $this->artisan('demo:user')
                ->expectsQuestion('Nombre ficticio', $name)
                ->expectsQuestion('Correo ficticio (.test)', $email)
                ->expectsOutput('Nombre o correo ficticio no válido.')
                ->assertFailed();
            $this->assertDatabaseCount('users', 0);
        }

        $this->artisan('demo:user')
            ->expectsQuestion('Nombre ficticio', 'Persona')
            ->expectsQuestion('Correo ficticio (.test)', 'persona@example.test')
            ->expectsChoice('Rol', 'treasury', ['treasury', 'board'])
            ->expectsQuestion('Contraseña ficticia', 'short')
            ->expectsQuestion('Confirma la contraseña', 'short')
            ->expectsOutput('La contraseña debe tener al menos 12 caracteres y coincidir.')
            ->doesntExpectOutputToContain('short')
            ->assertFailed();
        $this->assertDatabaseCount('users', 0);

        $this->artisan('demo:user')
            ->expectsQuestion('Nombre ficticio', 'Persona')
            ->expectsQuestion('Correo ficticio (.test)', 'persona@example.test')
            ->expectsChoice('Rol', 'treasury', ['treasury', 'board'])
            ->expectsQuestion('Contraseña ficticia', 'valid-demo-secret')
            ->expectsQuestion('Confirma la contraseña', 'valid-demo-secret')
            ->doesntExpectOutputToContain('valid-demo-secret')
            ->doesntExpectOutputToContain('$2y$')
            ->assertSuccessful();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('treasury', User::firstWhere('email', 'persona@example.test')->role);
    }
}
