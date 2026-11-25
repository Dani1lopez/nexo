<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuthTestCase;

/**
 * S20 and V8: the demo session keeps the real request-forgery protection, and a sandbox that
 * cannot be opened never falls through to the treasury database.
 */
class DemoBoundaryTest extends AuthTestCase
{
    public function test_real_csrf_is_enabled_on_entry_and_exit(): void
    {
        // The test client skips the token by default: run the middleware as a browser would.
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        $this->post('/demo/entrar')->assertStatus(419)->assertSessionMissing('demo.session');

        $this->withSession(['demo.session' => true]);

        $this->post('/demo/salir')->assertStatus(419);
        $this->assertTrue(session()->has('demo.session'));

        $this->post('/demo/salir', ['_token' => session()->token()])
            ->assertRedirect('/')
            ->assertSessionMissing('demo.session');
    }

    public function test_a_sandbox_connection_failure_does_not_run_the_controller(): void
    {
        config(['database.connections.demo.driver' => 'unavailable']);

        $this->withSession(['demo.session' => true])->get('/dashboard')->assertStatus(500);

        $this->assertGuest();
        $this->assertSame('sqlite', DB::getDefaultConnection());
        $this->assertDatabaseEmpty('users');
        $this->assertDatabaseEmpty('bank_movements');
    }
}
