<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Tests\Support\AuthTestCase;

class AuthenticationTest extends AuthTestCase
{
    public function test_login_logout_and_failed_credentials(): void
    {
        $this->account('ana@example.test', 'treasury', 'correct-password', 'Ana ficticia');
        foreach (['ana@example.test', 'missing@example.test'] as $email) {
            $this->from('/login')->post('/login', ['email' => $email, 'password' => 'wrong-password'])
                ->assertRedirect('/login')->assertSessionHasErrors('email')->assertSessionMissing('_old_input.password');
        }
        $before = session()->getId();
        $this->post('/login', ['email' => 'ana@example.test', 'password' => 'correct-password'])->assertRedirect('/dashboard');
        $this->assertNotSame($before, session()->getId());
        $token = session()->token();
        $this->get('/dashboard')->assertOk()->assertSee('Ana ficticia');
        $this->post('/logout')->assertRedirect('/login');
        $this->assertNotSame($token, session()->token());
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_five_failures_throttle_normalized_email_and_success_resets(): void
    {
        $this->account('ana@example.test', 'board', 'correct-password');
        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', ['email' => ' ANA@example.test ', 'password' => 'wrong-password']);
        }
        $this->post('/login', ['email' => 'ana@example.test', 'password' => 'correct-password'])->assertRedirect('/dashboard');
        $this->post('/logout');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'ana@example.test', 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => 'ANA@example.test', 'password' => 'correct-password'])->assertSessionHasErrors('email');
        $this->travel(61)->seconds();
        $this->post('/login', ['email' => 'ana@example.test', 'password' => 'correct-password'])->assertRedirect('/dashboard');
    }

    public function test_real_csrf_middleware_rejects_missing_and_invalid_tokens(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post('/login', [])->assertStatus(419);
        $this->post('/login', ['_token' => 'invalid'])->assertStatus(419);
        $this->account('ana@example.test', 'board', 'correct-password');
        $this->get('/login')->assertOk();
        $this->post('/login', ['_token' => session()->token(), 'email' => 'ana@example.test', 'password' => 'correct-password'])->assertRedirect('/dashboard');
    }

    public function test_guest_is_redirected_to_login_before_accessing_panel(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_login_form_identifies_its_credentials_without_registration(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('autocomplete="username"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertDontSee('Registro');
    }
}
