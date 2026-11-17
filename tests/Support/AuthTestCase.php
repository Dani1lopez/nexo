<?php

namespace Tests\Support;

use App\Modules\Access\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        return User::provision($name, $email, $password, $role);
    }

    /**
     * Store a role the application no longer writes, straight into the row, to prove
     * the authorization gates stay closed when stored data is wrong.
     */
    protected function accountWithStoredRole(string $email, string $role, string $password): User
    {
        DB::table('users')->insert([
            'name' => 'Persona ficticia',
            'email' => $email,
            'role' => $role,
            'password' => bcrypt($password),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::where('email', $email)->firstOrFail();
    }

    protected function attemptLogin(string $email, string $secret, array $extra = [], string $ip = '127.0.0.1', ?string $forwardedFor = null): TestResponse
    {
        $server = ['REMOTE_ADDR' => $ip];
        if ($forwardedFor !== null) {
            $server['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
        }

        return $this->withServerVariables($server)->post('/login', [...$extra, 'email' => $email, 'password' => $secret]);
    }
}
