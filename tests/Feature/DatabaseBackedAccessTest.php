<?php

namespace Tests\Feature;

use App\Modules\Access\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Exercises the real migrations on a file-based SQLite database with
 * database-backed sessions, which the in-memory/array suite does not cover.
 */
class DatabaseBackedAccessTest extends TestCase
{
    private string $databaseFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databaseFile = tempnam(sys_get_temp_dir(), 'nexo-access-');
        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databaseFile,
            'session.driver' => 'database',
            'cache.default' => 'database',
        ]);
        DB::purge('sqlite');

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        @unlink($this->databaseFile);

        parent::tearDown();
    }

    public function test_real_migrations_create_the_tables_access_depends_on(): void
    {
        foreach (['users', 'sessions', 'cache'] as $table) {
            $this->assertTrue(
                DB::getSchemaBuilder()->hasTable($table),
                "table [{$table}] must exist after the real migrations",
            );
        }
    }

    public function test_login_persists_the_database_session_and_logout_ends_access(): void
    {
        $user = User::provision('Persona ficticia', 'ana@example.test', 'treasury-secret-value', 'treasury');

        $this->get('/login')->assertOk();

        $this->post('/login', ['email' => 'ana@example.test', 'password' => 'treasury-secret-value'])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticated();

        $this->assertSame(
            1,
            DB::table('sessions')->where('user_id', $user->id)->count(),
            'the authenticated session must be stored against the user',
        );
        $this->get('/dashboard')->assertOk();
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_failed_login_throttling_uses_the_database_cache(): void
    {
        User::provision('Persona ficticia', 'ana@example.test', 'correct-secret-value', 'board');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'ana@example.test', 'password' => 'wrong-secret-value']);
        }

        $this->post('/login', ['email' => 'ana@example.test', 'password' => 'correct-secret-value'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertGreaterThan(0, DB::table('cache')->count(), 'limiter hits must be stored in the cache table');
    }
}
