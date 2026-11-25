<?php

namespace Tests\Feature;

use App\Modules\Access\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuthTestCase;

/**
 * Issue #74, T11 (S11, S20, V8, V9, V10): the demo session is isolated, grants no permission over
 * the treasury and never traps a real account.
 *
 * The demo keeps its own sandbox per request, its only persisted state is the chosen split in the
 * visitor's own session, and every real guard stays exactly where it was.
 */
class PracticeIsolationTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-05-21 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function enterDemo(): void
    {
        $this->post('/demo/entrar')->assertRedirect('/dashboard');
        $this->assertTrue(session('demo.session'));
    }

    /**
     * The real reconciliation form of the demo bank screen, read from the rendered HTML.
     *
     * @return array{action: string, key: string, allocations: list<string>}
     */
    private function reconcileForm(): array
    {
        $html = (string) $this->get('/bank')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<form[^>]*action="(\/bank\/movements\/\d+\/reconcile)"[^>]*>(.*?)<\/form>/s', $html, $matches));

        preg_match_all('/name="allocations\[(\d+)\]"/', $matches[0], $ids);
        preg_match('/name="submission_key" value="([^"]+)"/', $matches[0], $key);

        $this->assertCount(2, $ids[1]);
        $this->assertArrayHasKey(1, $key);

        return ['action' => $matches[1], 'key' => $key[1], 'allocations' => $ids[1]];
    }

    /** Walks the real screens exactly as a visitor does: dashboard, bank, reconcile, saldos. */
    private function walkTheFlow(): void
    {
        $form = $this->reconcileForm();

        $this->post($form['action'], [
            'submission_key' => $form['key'],
            'allocations' => [$form['allocations'][0] => '40,00', $form['allocations'][1] => '40,00'],
        ])->assertRedirect('/bank');

        $this->get('/saldos')->assertOk()->assertSeeInOrder(['Por cobrar', '0,00 €']);
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = [];

        foreach (['users', 'members', 'fee_years', 'dues', 'payments', 'bank_imports', 'bank_movements'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /** V10: without the demo mark every real route keeps its guard, and /demo responds as today. */
    public function test_a_guest_without_the_mark_still_gets_the_login_redirect(): void
    {
        foreach (['/bank', '/saldos', '/dashboard', '/members', '/fees', '/caja'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }

        $this->get('/demo')->assertOk()->assertSee('Demostración guiada');
        $this->post('/demo/conciliar', [
            'allocations' => ['socia_a' => '40,00', 'socio_b' => '40,00'],
        ])->assertOk()->assertSee('Reparto conciliado desde el extracto.');

        // /demo never writes a session mark and never leaves a demo session behind.
        $this->assertFalse(session()->has('demo.session'), 'la marca demo vive anidada en demo');
    }

    /** V10: the demo session does not open /demo, and /demo never opens the demo session. */
    public function test_the_demo_route_is_untouched_by_the_demo_session(): void
    {
        $this->enterDemo();

        $this->get('/demo')
            ->assertOk()
            ->assertSee('Demostración guiada')
            ->assertSee('Conciliar 80 €');

        $this->assertDatabaseCount('users', 0);
        $this->assertSame('sqlite', DB::getDefaultConnection());
    }

    /** V8: the demo writes nothing to the treasury database, whatever the visitor does. */
    public function test_the_demo_never_writes_to_the_treasury_database(): void
    {
        $this->account('tesoreria@example.test', 'treasury', 'treasury-secret-value', 'Ana Ficticia');

        $before = $this->counts();
        $this->assertSame(1, $before['users']);

        $this->enterDemo();
        $this->walkTheFlow();
        $this->get('/bank')->assertOk();
        $this->get('/dashboard')->assertOk();

        $this->assertSame($before, $this->counts());
        $this->assertDatabaseMissing('users', ['email' => 'equipo@ejemplo.test']);
        $this->assertSame('sqlite', DB::getDefaultConnection());
        $this->assertArrayNotHasKey('demo', DB::getConnections(), 'la conexión desechable se descarta en finally');
    }

    /** V8: two demo visitors never see each other's split. */
    public function test_two_demo_visitors_do_not_share_their_split(): void
    {
        $this->enterDemo();
        $this->walkTheFlow();

        $this->get('/bank')
            ->assertOk()
            ->assertSee('Conciliado con Socia Ejemplo A · 2026 · 40,00 €')
            ->assertSee('No hay cuotas pendientes a las que asignar este abono.');

        // A different visitor brings its own session, so it starts from the beginning.
        $this->flushSession();

        $this->get('/bank')->assertRedirect('/login');

        $this->enterDemo();

        $this->get('/bank')
            ->assertOk()
            ->assertSee('Quedan 100,00 € por conciliar.')
            ->assertDontSee('Ya se han conciliado');

        $this->get('/saldos')
            ->assertOk()
            ->assertSeeInOrder(['Sin conciliar', '100,00 €', '1 abono sin conciliar'])
            ->assertSeeInOrder(['Por cobrar', '80,00 €', '2 cuotas pendientes']);
    }

    /** V9: a real treasury user is never put in the demo and keeps their identity and role. */
    public function test_a_real_treasury_user_is_never_put_in_the_demo(): void
    {
        $user = $this->account('tesoreria@example.test', 'treasury', 'treasury-secret-value', 'Ana Ficticia');

        $this->attemptLogin('tesoreria@example.test', 'treasury-secret-value')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        // The demo entry is only for guests: a signed-in session is sent back to its own panel.
        $this->post('/demo/entrar')->assertRedirect('/dashboard');

        $this->assertFalse(session()->has('demo.session'), 'el acceso a la demo no deja marca en una sesión real');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('treasury', $user->fresh()->role);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Sesión iniciada como <strong>Ana Ficticia</strong>', false)
            ->assertSee('Tesorería, con edición');

        $this->get('/bank')
            ->assertOk()
            ->assertDontSee('TRANSFERENCIA DE EJEMPLO')
            ->assertDontSee('Salir de la demo');

        // No example row ever reaches the treasury tables.
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('members', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('bank_movements', 0);
    }

    /** V9: a leftover mark never overrides a real login, so nobody is trapped in the demo. */
    public function test_a_leftover_mark_never_overrides_a_real_login(): void
    {
        $this->enterDemo();

        $this->account('tesoreria@example.test', 'treasury', 'treasury-secret-value', 'Ana Ficticia');
        $this->attemptLogin('tesoreria@example.test', 'treasury-secret-value')->assertRedirect('/dashboard');

        $this->assertFalse(session()->has('demo.session'), 'el login real borra la marca demo');
        $this->assertAuthenticated();

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Sesión iniciada como <strong>Ana Ficticia</strong>', false);

        $this->get('/bank')
            ->assertOk()
            ->assertDontSee('TRANSFERENCIA DE EJEMPLO');
    }

    /** V10 and S19: leaving the demo clears both the mark and the split. */
    public function test_leaving_the_demo_removes_the_mark_and_the_split(): void
    {
        $this->enterDemo();
        $this->walkTheFlow();

        $this->assertSame(['socia_a' => 4000, 'socio_b' => 4000], session('demo.split'));

        $this->post('/demo/salir')->assertRedirect('/');
        $this->assertFalse(session()->has('demo.session'), 'la salida borra la marca demo');
        $this->assertFalse(session()->has('demo.split'), 'la salida borra el reparto demo');
        $this->assertGuest();

        foreach (['/bank', '/saldos', '/dashboard', '/members', '/fees', '/caja'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    /** S20: the example actor of the sandbox never becomes a real user of the application. */
    public function test_the_example_actor_never_becomes_a_real_user(): void
    {
        $this->enterDemo();
        $this->get('/bank')->assertOk();

        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
        $this->assertDatabaseMissing('users', ['email' => 'equipo@ejemplo.test']);
    }
}
