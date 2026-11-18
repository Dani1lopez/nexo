<?php

namespace Tests\Feature;

use Tests\Support\AuthTestCase;

/**
 * Issue #60: the public home page is the 3D landing (design/landing/04-viaje-de-camara.html).
 *
 * The scene is progressive enhancement: every word of the explanation is in the HTML, so
 * the page is complete without JavaScript, without WebGL and with reduced motion. Its Vite
 * entry (the only place that loads Three.js) is served on the home page and nowhere else.
 */
class LandingTest extends AuthTestCase
{
    /** Content of public/hot before the test, so an already running Vite server survives the run. */
    private ?string $hotMarkerBackup = null;

    private bool $hotMarkerExisted = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hotMarkerExisted = is_file($this->hotMarker());
        if ($this->hotMarkerExisted) {
            $this->hotMarkerBackup = (string) file_get_contents($this->hotMarker());
        }

        $this->removeHotMarker();
    }

    protected function tearDown(): void
    {
        $this->removeHotMarker();
        if ($this->hotMarkerExisted && $this->hotMarkerBackup !== null) {
            file_put_contents($this->hotMarker(), $this->hotMarkerBackup);
        }

        parent::tearDown();
    }

    private function hotMarker(): string
    {
        return public_path('hot');
    }

    private function removeHotMarker(): void
    {
        if (is_file($this->hotMarker())) {
            unlink($this->hotMarker());
        }
    }

    public function test_the_home_page_is_the_landing_and_explains_the_project_without_javascript(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('<html lang="es"', false)
            ->assertSee('data-theme="dark"', false)
            ->assertSee('<meta name="color-scheme" content="dark">', false)
            // Opening: what Nexo is, in the first viewport, with the only primary action.
            ->assertSee('Tesorería para asociaciones')
            ->assertSee('Las cuentas')
            ->assertSee('para quien venga')
            ->assertSee('Socios, cuotas y movimientos del banco en un solo sitio.')
            ->assertSee('Entrar a la demo')
            ->assertSee('href="/login"', false)
            ->assertSee('Desplázate para viajar')
            // The four chapters exist as text, not only inside the canvas.
            ->assertSee('Capítulo 01')
            ->assertSee('Una cuota entra en la asociación.')
            ->assertSee('Capítulo 02')
            ->assertSee('El banco habla en CSV.')
            ->assertSee('Capítulo 03')
            ->assertSee('Un abono, dos cuotas.')
            ->assertSee('Capítulo 04')
            ->assertSee('La junta lee. La tesorería trabaja.')
            ->assertSee('María transfiere 30,00 €')
            // Manifesto: the same features the demo already has, and its limits.
            ->assertSee('Cuentas claras para el siguiente equipo.')
            ->assertSee('Qué incluye la demo')
            ->assertSee('conciliación manual con reparto de un abono entre varias cuotas y un resumen de saldos con la deuda por ejercicio')
            ->assertSee('No hay cobros reales ni conexión bancaria')
            ->assertSee('Esta demo no garantiza cumplimiento fiscal, contable o legal.')
            ->assertSee('No utilices datos personales ni dinero real.')
            ->assertSee('Datos ficticios')
            // Static fallback: the thread and its four stops are drawn without WebGL too.
            ->assertSee('lp-fallback', false)
            ->assertSee('lp-fallback-line', false)
            ->assertSee('--thread-saldos', false);
    }

    /** The scene must never need inline code: the strict CSP has no 'unsafe-inline' for scripts. */
    public function test_the_landing_ships_no_inline_script_and_no_form(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>', (string) $html);
        $this->assertStringNotContainsString('<form', (string) $html);
        $this->assertStringNotContainsString('eval(', (string) $html);
    }

    /** Issue #60 S2: Three.js lives in the landing entry, which only the home page includes. */
    public function test_the_landing_entry_is_included_only_on_the_public_home(): void
    {
        file_put_contents($this->hotMarker(), 'http://127.0.0.1:5173');
        $this->withVite();

        $this->get('/')
            ->assertOk()
            ->assertSee('/resources/css/landing.css', false)
            ->assertSee('/resources/js/landing.js', false);

        $this->get('/login')
            ->assertOk()
            ->assertDontSee('/resources/js/landing.js', false)
            ->assertDontSee('/resources/css/landing.css', false)
            ->assertSee('/resources/js/theme.js', false);

        $this->actingAs($this->account('junta@example.test', 'board', 'fictitious-secret-value'))
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('/resources/js/landing.js', false)
            ->assertDontSee('/resources/css/landing.css', false)
            ->assertSee('/resources/js/theme.js', false);
    }

    /** The landing is dark only, so it never offers the light theme toggle of the rest of the shell. */
    public function test_the_landing_keeps_one_theme_and_one_destination_for_its_actions(): void
    {
        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('theme-toggle', $html);
        $this->assertStringNotContainsString('Modo claro', $html);
        // Two links, one action: the opening button and the closing "mega" call to action,
        // both labelled the same and both pointing at /login. Nothing else competes with them.
        $this->assertSame(2, substr_count($html, 'Entrar a la demo'));
        $this->assertSame(2, substr_count($html, 'href="/login"'));
        $this->assertSame(1, substr_count($html, 'class="lp-button"'));
        $this->assertSame(1, substr_count($html, 'class="lp-mega"'));
        $this->assertSame(0, substr_count($html, 'Acceder'));
    }

    /**
     * The copy has to match what the application really does (issue #60 review):
     * the annual fee is charged in full (a smaller payment is partial, not prorated), the
     * second factor is optional and only asked after enrolment, and the bank figure is the
     * net of the imported statement, not a live balance.
     */
    public function test_the_landing_copy_matches_the_behaviour_of_the_application(): void
    {
        $response = $this->get('/')->assertOk();

        // The annual fee is never prorated (app/Modules/Fees/Models/Due.php).
        $response->assertSee('Ana ha pagado 10,00 € de los 15,00 € de la cuota anual')
            ->assertSee('pago parcial')
            ->assertDontSee('prorrate')
            ->assertDontSee('prorrateo')
            ->assertDontSee('proporcional')
            ->assertDontSee('porque se dio de alta en septiembre')
            // The second factor is optional and only appears after enrolling (AttemptLogin).
            ->assertSee('segundo factor opcional')
            ->assertSee('puede activarse')
            ->assertDontSee('código TOTP de seis cifras para entrar')
            // The bank figure is what the imported statement sums, not a live balance.
            ->assertSee('Saldo importado')
            ->assertSee('suma de los movimientos importados')
            ->assertDontSee('En banco');
    }
}
