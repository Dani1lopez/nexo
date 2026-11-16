<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
