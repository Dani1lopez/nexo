<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\AuthTestCase;

/**
 * Issue #74, T8 and T11 (S15, S21, V1, V10): the parallel walkthrough is gone and the guided
 * demo is a session that runs on the real URLs.
 *
 * There is no /practica route and no walkthrough panel any more: the only entry is the explicit
 * «Probar sin cuenta» action of the access screen, which marks the visitor's session as demo,
 * and the only exit is the «Salir de la demo» action of the shared layout.
 */
class PracticeEntryTest extends AuthTestCase
{
    /** V1 and S21: no walkthrough route survives, in route:list or on the wire. */
    public function test_the_walkthrough_routes_are_gone_from_the_application(): void
    {
        $uris = array_map(
            fn ($route): string => (string) $route->uri(),
            Route::getRoutes()->getRoutes(),
        );

        $this->assertNotEmpty($uris);

        foreach ($uris as $uri) {
            $this->assertStringStartsNotWith('practica', $uri, 'ninguna ruta de práctica sigue listada');
        }

        $this->get('/practica')->assertNotFound();
        $this->get('/practica/banco')->assertNotFound();
        $this->get('/practica/saldos')->assertNotFound();
        $this->post('/practica/banco/conciliar')->assertNotFound();
        $this->post('/practica/reiniciar')->assertNotFound();
        $this->post('/practica/salir')->assertNotFound();
    }

    /** D1: the public home keeps one entry, «Entrar a la demo», and no walkthrough link. */
    public function test_the_public_home_keeps_only_the_demo_entry(): void
    {
        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertSame(0, substr_count($html, 'Probar Nexo'));
        $this->assertStringNotContainsString('href="/practica"', $html);
        $this->assertStringNotContainsString('Probar con datos de ejemplo', $html);

        // The primary action of the landing is untouched, in the opening and in the close.
        $this->assertSame(2, substr_count($html, 'Entrar a la demo'));
        $this->assertSame(2, substr_count($html, 'href="/login"'));
    }

    /** S16: the access screen offers an explicit, credential-free demo entry. */
    public function test_the_access_screen_offers_the_demo_entry_without_credentials(): void
    {
        $html = (string) $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('action="/demo/entrar"', $html);
        $this->assertStringContainsString('Probar sin cuenta', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringNotContainsString('href="/practica"', $html);

        // The real form is still there, unchanged.
        $this->assertStringContainsString('action="/login"', $html);
        $this->assertStringContainsString('Entrar', $html);
    }

    /** V1: the demo entry opens the real panel and the URL stays /dashboard. */
    public function test_the_demo_entry_opens_the_real_panel_without_leaving_it(): void
    {
        $this->post('/demo/entrar')->assertRedirect('/dashboard');
        $this->assertTrue(session('demo.session'));

        $html = (string) $this->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('<h1>Panel', $html);
        // The visitor walks the real screens and leaves the demo from the same shell.
        $this->assertStringContainsString('aria-label="Principal"', $html);
        $this->assertStringContainsString('action="/demo/salir"', $html);
        $this->assertStringContainsString('Salir de la demo', $html);
        // The signed-in chrome of a real account is never shown in a demo session.
        $this->assertStringNotContainsString('Cerrar sesión', $html);
        $this->assertStringNotContainsString('Modo práctica', $html);

        // The sandbox is that of the request: the treasury database is untouched.
        $this->assertSame('sqlite', DB::getDefaultConnection());
        $this->assertDatabaseCount('users', 0);
    }

    /** V10: leaving the demo clears its state and the real guards close again. */
    public function test_leaving_the_demo_clears_the_mark_and_returns_to_the_home(): void
    {
        $this->post('/demo/entrar')->assertRedirect('/dashboard');
        $this->assertTrue(session('demo.session'));

        $this->post('/demo/salir')->assertRedirect('/');

        $this->assertFalse(session()->has('demo.session'), 'la marca demo no queda en la sesión');
        $this->assertFalse(session()->has('demo.split'), 'el reparto demo no queda en la sesión');

        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/bank')->assertRedirect('/login');
    }

    /** The demo entry has its own named limiter, separate from the one of /demo. */
    public function test_the_demo_entry_is_rate_limited_without_touching_the_demo_endpoint(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->post('/demo/entrar')->assertRedirect('/dashboard');
        }

        $this->post('/demo/entrar')->assertStatus(429);

        // /demo keeps its own counter and still answers.
        $this->get('/demo')->assertOk()->assertSee('Demostración guiada');
    }

    /** H4: leaving the demo has its own named limiter, separate from the entry and from /demo. */
    public function test_the_demo_exit_is_rate_limited_without_spending_the_entry_budget(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->post('/demo/salir')->assertRedirect('/');
        }

        $this->post('/demo/salir')->assertStatus(429);

        // The entry keeps its own budget, so a throttled exit never traps the next visit.
        $this->post('/demo/entrar')->assertRedirect('/dashboard');
        $this->get('/demo')->assertOk()->assertSee('Demostración guiada');
    }
}
