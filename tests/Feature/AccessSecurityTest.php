<?php

namespace Tests\Feature;

use App\Modules\Access\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
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
        foreach (['', 'Treasury', 'BOARD', 'treasury ', 'admin'] as $i => $role) {
            $user = $this->accountWithStoredRole("rol{$i}@example.test", $role, 'unknown-secret-value');
            $this->assertFalse(Gate::forUser($user)->allows('view-treasury'), "role [{$role}] must not view");
            $this->assertFalse(Gate::forUser($user)->allows('edit-treasury'), "role [{$role}] must not edit");
            $this->actingAs($user)->get('/dashboard')->assertForbidden();
            $this->post('/logout');
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
            ->assertSessionHasErrors(['email' => 'Las credenciales no son válidas.']);
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

    public function test_lockout_message_is_indistinguishable_from_invalid_credentials(): void
    {
        $this->account('ana@example.test', 'board', 'correct-secret-value');
        $generic = ['email' => 'Las credenciales no son válidas.'];

        for ($i = 0; $i < 5; $i++) {
            $this->from('/login')->attemptLogin('ana@example.test', 'wrong-secret-value')->assertSessionHasErrors($generic);
        }

        $this->from('/login')->attemptLogin('ana@example.test', 'correct-secret-value')
            ->assertRedirect('/login')
            ->assertSessionHasErrors($generic)
            ->assertSessionDoesntHaveErrors(['email' => 'Demasiados intentos. Inténtalo de nuevo más tarde.']);
        $this->assertGuest();
    }

    public function test_forwarded_for_header_is_ignored_without_trusted_proxies(): void
    {
        config(['trustedproxy.proxies' => null]);
        $this->account('ana@example.test', 'board', 'correct-secret-value');

        for ($i = 0; $i < 5; $i++) {
            $this->attemptLogin('ana@example.test', 'wrong-secret-value', forwardedFor: "203.0.113.{$i}");
        }

        $this->attemptLogin('ana@example.test', 'correct-secret-value', forwardedFor: '198.51.100.20')
            ->assertSessionHasErrors(['email' => 'Las credenciales no son válidas.']);
        $this->assertGuest();
    }

    public function test_forwarded_for_header_is_used_when_the_proxy_is_trusted(): void
    {
        config(['trustedproxy.proxies' => ['127.0.0.1']]);
        $this->account('ana@example.test', 'board', 'correct-secret-value');

        for ($i = 0; $i < 5; $i++) {
            $this->attemptLogin('ana@example.test', 'wrong-secret-value', forwardedFor: '203.0.113.10');
        }

        $this->attemptLogin('ana@example.test', 'correct-secret-value', forwardedFor: '198.51.100.20')
            ->assertRedirect('/dashboard');
        $this->assertAuthenticated();
    }

    public function test_a_forged_forge_host_does_not_unlock_forwarded_headers(): void
    {
        // The case is about an app with no trusted proxy: pin it so a developer's
        // TRUSTED_PROXIES in the environment cannot turn this into a different scenario.
        config(['trustedproxy.proxies' => []]);
        $this->account('ana@example.test', 'board', 'correct-secret-value');

        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => "203.0.113.{$i}"])
                ->post('http://evil.on-forge.com/login', ['email' => 'ana@example.test', 'password' => 'wrong-secret-value']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.20'])
            ->post('http://evil.on-forge.com/login', ['email' => 'ana@example.test', 'password' => 'correct-secret-value'])
            ->assertSessionHasErrors(['email' => 'Las credenciales no son válidas.']);
        $this->assertGuest();
    }

    public function test_a_stored_unknown_role_does_not_break_other_saves(): void
    {
        $user = $this->accountWithStoredRole('otro@example.test', 'admin', 'unknown-secret-value');

        $user->name = 'Nombre actualizado';
        $user->save();

        $this->assertSame('Nombre actualizado', $user->fresh()->name);
        $this->assertSame('admin', $user->fresh()->role);
        $this->assertFalse(Gate::forUser($user->fresh())->allows('view-treasury'));
    }

    public function test_role_is_not_mass_assignable(): void
    {
        $user = User::provision('Persona ficticia', 'falsa@example.test', 'fictitious-secret-value', 'board');

        $user->fill(['role' => 'treasury'])->save();

        $this->assertSame('board', $user->fresh()->role);
        $this->assertFalse(Gate::forUser($user->fresh())->allows('edit-treasury'));
    }

    public function test_only_known_roles_can_be_assigned(): void
    {
        $user = User::provision('Persona ficticia', 'falsa@example.test', 'fictitious-secret-value', 'board');

        $this->expectException(InvalidArgumentException::class);
        $user->assignRole('admin');
    }

    public function test_a_known_role_can_be_assigned_explicitly(): void
    {
        $user = User::provision('Persona ficticia', 'falsa@example.test', 'fictitious-secret-value', 'board');
        $user->assignRole('treasury')->save();

        $this->assertSame('treasury', $user->fresh()->role);
        $this->assertTrue(Gate::forUser($user->fresh())->allows('edit-treasury'));
    }

    public function test_the_hashing_policy_is_explicit_and_never_stores_the_clear_password(): void
    {
        $user = $this->account('ana@example.test', 'treasury', 'treasury-secret-value');

        $this->assertSame('bcrypt', config('hashing.driver'));
        $this->assertTrue(config('hashing.rehash_on_login'));

        $hash = (string) $user->fresh()->password;
        $this->assertNotSame('treasury-secret-value', $hash);
        $this->assertMatchesRegularExpression('/^\$2y\$\d{2}\$/', $hash);
        $this->assertTrue(Hash::check('treasury-secret-value', $hash));
    }

    public function test_an_outdated_hash_is_upgraded_on_the_next_login(): void
    {
        $user = $this->account('ana@example.test', 'treasury', 'treasury-secret-value');

        // A hash written when the cost was lower: the row is updated straight through the
        // database so the hashed cast does not re-hash it on the way in.
        DB::table('users')->where('id', $user->id)
            ->update(['password' => Hash::make('treasury-secret-value', ['rounds' => 4])]);
        config(['hashing.bcrypt.rounds' => 8]);
        app('hash')->forgetDrivers();

        $this->attemptLogin('ana@example.test', 'treasury-secret-value')->assertRedirect('/dashboard');

        $upgraded = (string) $user->fresh()->password;
        $this->assertStringStartsWith('$2y$08$', $upgraded);
        $this->assertTrue(Hash::check('treasury-secret-value', $upgraded));
    }
}
