<?php

namespace Tests\Feature;

use App\Modules\Access\Models\User;
use App\Modules\Access\Support\Totp;
use App\Modules\Demo\Support\DemoSession;
use Tests\Support\AuthTestCase;

/**
 * H5: the demo mark is not authentication, so the pre-authentication screen of a real login with a
 * second factor must pass through untouched — otherwise the visitor of the demo can never finish
 * the real login — while the two-factor routes that manage an account keep bouncing inside the
 * demo, because in the sandbox they would edit the throwaway actor.
 */
class DemoTwoFactorLoginTest extends AuthTestCase
{
    public function test_a_marked_visitor_completes_a_real_login_with_a_second_factor(): void
    {
        $user = $this->account('real-2fa@fixture.test', 'treasury', 'a-fixture-passphrase', 'Real fixture');
        $factor = $this->enableTwoFactor($user);
        $this->post('/logout');

        $this->withSession([
            DemoSession::MARK => true,
            DemoSession::SPLIT => ['socia_a' => 4000],
        ]);

        $this->attemptLogin('real-2fa@fixture.test', 'a-fixture-passphrase')
            ->assertRedirect('/login/segundo-factor');
        $this->assertGuest();
        $this->assertTrue(session()->has(DemoSession::MARK), 'el login pendiente todavía no establece la identidad real');

        // The marked visitor gets the challenge itself, not the dashboard of the sandbox.
        $this->get('/login/segundo-factor')
            ->assertOk()
            ->assertSee('código de 6 dígitos');
        $this->assertGuest();
        // Passing through leaves the mark alone: only the real identity drops it.
        $this->assertTrue(session()->has(DemoSession::MARK));

        $this->post('/login/segundo-factor', ['code' => $this->code($factor)])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('two_factor.pending_user_id'));
        $this->assertFalse(session()->has(DemoSession::MARK), 'la identidad real borra la marca demo');
        $this->assertFalse(session()->has(DemoSession::SPLIT), 'la identidad real borra el reparto demo');

        // Nothing of the demo survives the real login: the dashboard is the real treasury screen.
        $this->get('/dashboard')->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_marked_guest_is_still_bounced_from_the_two_factor_management_routes(): void
    {
        $user = $this->account('real-2fa@fixture.test', 'treasury', 'a-fixture-passphrase', 'Real fixture');
        $factor = $this->enableTwoFactor($user);
        $this->post('/logout');

        $this->withSession([DemoSession::MARK => true]);

        $this->get('/access/two-factor')->assertRedirect('/dashboard');
        $this->post('/access/two-factor')->assertRedirect('/dashboard');
        $this->post('/access/two-factor/confirm', ['code' => $this->code($factor)])->assertRedirect('/dashboard');
        $this->post('/access/two-factor/disable', ['code' => $this->code($factor)])->assertRedirect('/dashboard');

        // The real account of the person is untouched, and the visitor is still a guest of the demo.
        $user->refresh();
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertNotNull($user->two_factor_secret);
        $this->assertGuest();
        $this->assertTrue(session()->has(DemoSession::MARK));
    }

    /**
     * Complete the real enrollment flow and leave the user signed in.
     */
    private function enableTwoFactor(User $user): string
    {
        $this->actingAs($user)->post('/access/two-factor')->assertRedirect('/access/two-factor');

        $factor = (string) $user->fresh()->two_factor_secret;
        $this->post('/access/two-factor/confirm', ['code' => $this->code($factor)])
            ->assertRedirect('/access/two-factor');
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);

        return $factor;
    }

    private function code(string $factor, ?int $now = null): string
    {
        return (new Totp)->code($factor, $now ?? time());
    }
}
