<?php

namespace Tests\Support;

use App\Modules\Access\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class AuthTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    protected function account(string $email, string $role, string $password, string $name = 'Persona ficticia'): User
    {
        return User::create(compact('name', 'email', 'role', 'password'));
    }

    protected function attemptLogin(string $email, string $secret, array $extra = [], string $ip = '127.0.0.1'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/login', [...$extra, 'email' => $email, 'password' => $secret]);
    }
}
