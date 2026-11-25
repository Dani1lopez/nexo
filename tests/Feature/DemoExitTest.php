<?php

namespace Tests\Feature;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AuthTestCase;

/**
 * Issue #74, REG-001 and R1-001: only an active demo session is discarded by `POST /demo/salir`,
 * and the screens the demo serves are serialized with that exit so a late response of the demo
 * cannot restore a session the visitor already left.
 *
 * The session store is the database one in every case here, because the assertions are about the
 * stored row: the exit must not delete the row of a real account, and a row the demo wrote after
 * the exit must not be served as demo again. Each browser pins its cookie, so every request reads
 * the stored row like a real browser would instead of the copy the previous request left in memory.
 */
class DemoExitTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The stored row is the subject, and the session lock of the demo runs on the same store
        // the application uses by default (`cache.default` => database, locks in `cache_locks`).
        config([
            'session.driver' => 'database',
            'cache.default' => 'database',
        ]);
    }

    /**
     * A request that reads the stored row instead of the in-memory one, like a separate browser.
     */
    private function browser(string $id): void
    {
        app('session')->forgetDrivers();
        $this->app->forgetInstance('session.store');
        Auth::forgetGuards();

        $this->withCookie(config('session.cookie'), $id);
    }

    /**
     * The test client skips the token by default: run the middleware as a browser would.
     */
    private function withRealCsrf(): void
    {
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    /** The stored payload of a session row, decoded as the framework writes it. */
    private function payload(string $id): array
    {
        $stored = DB::table('sessions')->where('id', $id)->value('payload');

        $this->assertIsString($stored, "la sesión [{$id}] está guardada");

        return json_decode(base64_decode($stored), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Walks the demo on a pinned session; returns the identifier the visitor ends up holding. */
    private function walkTheDemo(string $id): string
    {
        $this->browser($id);
        $this->post('/demo/entrar')->assertRedirect('/dashboard');

        $id = session()->getId();
        $this->browser($id);
        $bank = (string) $this->get('/bank')->assertOk()->getContent();

        $this->assertSame(1, preg_match('~<form[^>]*action="(/bank/movements/\d+/reconcile)"~', $bank, $action));
        $this->assertSame(1, preg_match('~name="submission_key" value="([^"]+)"~', $bank, $key));
        preg_match_all('~name="allocations\[(\d+)\]"~', $bank, $dues);

        $this->assertCount(2, $dues[1]);

        $this->browser($id);
        $this->post($action[1], [
            'submission_key' => $key[1],
            'allocations' => array_fill_keys($dues[1], '40,00'),
        ])->assertRedirect('/bank');

        return $id;
    }

    /** REG-001: a valid exit POST from a real account never signs the person out. */
    public function test_a_real_account_keeps_its_session_when_it_posts_the_demo_exit(): void
    {
        $user = $this->account('tesoreria@fixture.test', 'treasury', 'a-fixture-passphrase');
        $this->attemptLogin('tesoreria@fixture.test', 'a-fixture-passphrase')->assertRedirect('/dashboard');

        $id = session()->getId();
        $token = (string) session()->token();
        $guardKey = Auth::guard('web')->getName();

        $this->assertFalse(session()->has('demo.session'), 'el usuario real no tiene la marca demo');

        $this->withRealCsrf();
        $this->browser($id);

        $this->post('/demo/salir', ['_token' => $token])->assertRedirect('/dashboard');

        $this->assertSame($id, session()->getId(), 'la salida no invalida la sesión de un usuario real');
        $this->assertTrue(session()->has($guardKey), 'la sesión real conserva su identidad');

        $payload = $this->payload($id);
        $this->assertArrayHasKey($guardKey, $payload, 'la fila de la sesión real sigue guardada');

        // The same cookie still reaches the real panel, as the same account.
        $this->browser($id);
        $this->get('/dashboard')->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    /** REG-001: a guest without the mark keeps its session exactly as it was. */
    public function test_a_guest_without_the_mark_keeps_its_session_untouched(): void
    {
        $id = str_repeat('G', 40);

        $this->browser($id);
        $this->get('/login')->assertOk();

        $this->withSession(['kept' => 'value']);

        $this->post('/demo/salir')->assertRedirect('/');

        $this->assertSame($id, session()->getId(), 'la salida no invalida una sesión que no es demo');
        $this->assertSame('value', session('kept'), 'los datos de la sesión siguen ahí');
        $this->assertFalse(session()->has('demo.session'));
        $this->assertSame('value', $this->payload($id)['kept'] ?? null, 'la fila guardada conserva sus datos');

        $this->browser($id);
        $this->get('/login')->assertOk();
        $this->assertGuest();
    }

    /** The exit of an active demo drops the mark, the split and the row it lived in. */
    public function test_an_active_demo_exit_discards_the_mark_and_the_split(): void
    {
        $id = $this->walkTheDemo(str_repeat('C', 40));

        $stored = $this->payload($id);
        $this->assertTrue($stored['demo']['session'] ?? false, 'la marca demo está guardada');
        $this->assertNotEmpty($stored['demo']['split'] ?? null, 'el reparto está guardado');

        $this->post('/demo/salir')->assertRedirect('/');

        $fresh = session()->getId();

        $this->assertNotSame($id, $fresh, 'la salida entrega un identificador de sesión nuevo');
        $this->assertFalse(session()->has('demo.session'), 'la marca demo se borra en la salida');
        $this->assertFalse(session()->has('demo.split'), 'el reparto demo se borra en la salida');
        $this->assertNull(DB::table('sessions')->find($id), 'la fila de la demo se descarta');

        $this->browser($fresh);
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/bank')->assertRedirect('/login');
    }

    /**
     * R1-001: a late demo response can put the old snapshot back on the discarded id — a store that
     * accepts writes on a deleted id restores the row, mark and split included — and the browser can
     * still carry that old cookie. Neither cookie may put the visitor back in the demo.
     */
    public function test_a_stale_demo_snapshot_cannot_resurrect_the_demo_with_either_cookie(): void
    {
        $id = $this->walkTheDemo(str_repeat('S', 40));

        $snapshot = DB::table('sessions')->where('id', $id)->value('payload');
        $this->assertIsString($snapshot);
        $this->assertNotSame('', $snapshot);

        $this->post('/demo/salir')->assertRedirect('/');

        $fresh = session()->getId();
        $this->assertNotSame($id, $fresh);

        // The late response of the demo writes its older snapshot on the id the exit discarded.
        DB::table('sessions')->insert([
            'id' => $id,
            'payload' => $snapshot,
            'last_activity' => now()->getTimestamp(),
        ]);

        // The browser replays the old cookie first: the row is there, the demo is not.
        $this->browser($id);
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertFalse(session()->has('demo.session'), 'la marca vieja no vuelve a activar la demo');
        $this->get('/bank')->assertRedirect('/login');

        // And with the cookie the exit handed out, the visitor is just a guest.
        $this->browser($fresh);
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertFalse(session()->has('demo.session'));
    }

    /** R1-001 / R4-001: a browser that still holds a retired identifier re-enters the demo. */
    public function test_a_browser_holding_a_retired_id_can_enter_the_demo_again(): void
    {
        $retired = $this->walkTheDemo(str_repeat('R', 40));

        $snapshot = DB::table('sessions')->where('id', $retired)->value('payload');
        $this->assertIsString($snapshot);

        $this->post('/demo/salir')->assertRedirect('/');

        // The late response of the demo puts the mark back on the identifier the exit retired.
        DB::table('sessions')->insert([
            'id' => $retired,
            'payload' => $snapshot,
            'last_activity' => now()->getTimestamp(),
        ]);

        $this->browser($retired);
        $this->post('/demo/entrar')->assertRedirect('/dashboard');

        $entered = session()->getId();
        $this->browser($entered);
        $this->get('/dashboard')->assertOk();
        $this->assertSame(true, session('demo.session'), 'la entrada vuelve a marcar la sesión demo');
        $this->assertNotSame($retired, $entered, 'la entrada entrega un identificador nuevo');
    }

    /** R1-001: the demo-served routes and both demo actions hold the session lock. */
    public function test_the_demo_routes_and_the_demo_exit_hold_the_session_lock(): void
    {
        $blocked = [
            'dashboard',
            'summary.show',
            'bank.index',
            'bank.movements.reconcile',
            'members.index',
            'fees.index',
            'fees.show',
            'cash.index',
            'demo.enter',
            'demo.exit',
            // R4-002: the routes of #73 save the same session, so they queue with the rest.
            'demo.show',
            'demo.reconcile',
        ];

        foreach ($blocked as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "la ruta [{$name}] existe");
            $this->assertSame(10, $route->locksFor(), "la ruta [{$name}] serializa las peticiones de la sesión");
            $this->assertSame(10, $route->waitsFor(), "la ruta [{$name}] espera su turno");
        }

        // Least blast radius: a treasury route the demo does not serve keeps running unblocked.
        foreach (['members.store', 'bank.imports.store', 'cash.opening.store'] as $name) {
            $this->assertNull(Route::getRoutes()->getByName($name)?->locksFor(), "la ruta [{$name}] no se serializa");
        }
    }

    /** The lock the demo relies on has a lock-capable store and its table in the real schema. */
    public function test_the_session_lock_has_a_lock_capable_store(): void
    {
        // The suite runs the array store; config/cache.php keeps `database` as the default one of
        // the application. Both hand out a lock, and the table the database store locks in exists.
        $this->assertInstanceOf(LockProvider::class, Cache::store(config('cache.default'))->getStore());
        $this->assertSame('database', config('cache.stores.database.driver'));
        $this->assertInstanceOf(LockProvider::class, Cache::store('database')->getStore());
        $this->assertTrue(Schema::hasTable('cache_locks'), 'la tabla de bloqueos existe tras las migraciones');

        $lock = Cache::store('array')->lock('session:'.str_repeat('L', 40), 10);

        $this->assertTrue($lock->get(), 'el almacén entrega el bloqueo');
        $this->assertFalse(
            Cache::store('array')->lock('session:'.str_repeat('L', 40), 10)->get(),
            'el mismo bloqueo no se entrega dos veces',
        );

        $lock->release();
    }
}
