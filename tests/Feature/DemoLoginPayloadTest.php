<?php

namespace Tests\Feature;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuthTestCase;

/**
 * H2: a real login with the same demo cookie never carries the demo state into the session it
 * stores, not even until the next request.
 *
 * The invariant is checked on the stored payload, the only place a leftover mark would survive:
 * Laravel writes `demo.session` nested under `demo`, so a literal top-level key proves nothing.
 */
class DemoLoginPayloadTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    public function test_explicit_login_with_the_same_demo_cookie(): void
    {
        $user = $this->account('real@fixture.test', 'treasury', 'a-fixture-passphrase', 'Real fixture');

        $this->post('/demo/entrar')->assertRedirect('/dashboard');

        // The visitor keeps the same browser session for the real login.
        $this->withCookie(config('session.cookie'), session()->getId());

        $this->attemptLogin('real@fixture.test', 'a-fixture-passphrase')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        $row = DB::table('sessions')->find(session()->getId());

        $this->assertNotNull($row);

        $payload = json_decode(base64_decode($row->payload), true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse(Arr::has($payload, 'demo.session'), 'la marca demo no se guarda al iniciar sesión real');
        $this->assertFalse(Arr::has($payload, 'demo.split'), 'el reparto demo no se guarda al iniciar sesión real');

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Real fixture')
            ->assertDontSee('Salir de la demo');

        $this->assertFalse(session()->has('demo.session'));
    }
}
