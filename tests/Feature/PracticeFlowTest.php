<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuthTestCase;

/**
 * Issue #74, T11 (S15, S16, S17, V5, V8): the guided demo runs on the real screens.
 *
 * /bank serves the real reconcile control with its real action and one submission_key, the real
 * ReconcileController and ReconcileMovement apply the split inside the request's sandbox and the
 * response is the same redirect and the same status as the real screen. Nothing of the split
 * reaches the treasury database.
 */
class PracticeFlowTest extends AuthTestCase
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

    /**
     * The real reconciliation form of the demo bank screen, read from the rendered HTML.
     *
     * @return array{action: string, key: string, allocations: list<string>, html: string}
     */
    private function reconcileForm(): array
    {
        $html = (string) $this->get('/bank')->assertOk()->getContent();

        $this->assertSame(
            1,
            preg_match('/<form[^>]*action="(\/bank\/movements\/\d+\/reconcile)"[^>]*>(.*?)<\/form>/s', $html, $matches),
            'la pantalla muestra un único formulario real de conciliación',
        );

        $form = $matches[0];
        preg_match_all('/name="allocations\[(\d+)\]"/', $form, $ids);
        preg_match('/name="submission_key" value="([^"]+)"/', $form, $key);

        $this->assertCount(2, $ids[1], 'el reparto ofrece las dos cuotas del escenario');
        $this->assertArrayHasKey(1, $key, 'el formulario lleva su submission_key');

        return ['action' => $matches[1], 'key' => $key[1], 'allocations' => $ids[1], 'html' => $form];
    }

    private function enterDemo(): void
    {
        $this->post('/demo/entrar')->assertRedirect('/dashboard');
        $this->assertTrue(session('demo.session'));
    }

    /** V5 and S17: one real reconcile form, with the real action and one submission_key. */
    public function test_the_demo_serves_the_real_reconcile_form_on_bank(): void
    {
        $this->enterDemo();

        $html = (string) $this->get('/bank')->assertOk()->getContent();

        // The real screen, with the real headings and the fixed scenario of #73.
        $this->assertStringContainsString('<h1>Movimientos bancarios</h1>', $html);
        $this->assertStringContainsString('Importes en euros, con datos exclusivamente ficticios.', $html);
        $this->assertStringContainsString('TRANSFERENCIA DE EJEMPLO', $html);
        $this->assertStringContainsString('21/05/2026', $html);
        $this->assertStringContainsString('100,00 €', $html);
        $this->assertStringContainsString('Quedan 100,00 € por conciliar.', $html);
        $this->assertStringContainsString('Conciliar el reparto', $html);
        $this->assertStringContainsString('Socia Ejemplo A', $html);
        $this->assertStringContainsString('Socio Ejemplo B', $html);

        $form = $this->reconcileForm();

        // Exactly one form pointing at the real action, and exactly one submission_key in it.
        $this->assertSame(1, substr_count($html, 'action="'.$form['action'].'"'));
        $this->assertSame(1, substr_count($form['html'], 'name="submission_key"'));
        $this->assertStringNotContainsString('action="/practica', $html);
    }

    /** S17: confirming keeps the real redirect and the real status of /bank. */
    public function test_confirming_the_split_keeps_the_real_redirect_and_status(): void
    {
        $this->enterDemo();
        $form = $this->reconcileForm();

        $this->post($form['action'], [
            'submission_key' => $form['key'],
            'allocations' => [$form['allocations'][0] => '40,00', $form['allocations'][1] => '40,00'],
        ])->assertRedirect('/bank');

        // Only the chosen split survives between requests, keyed by the scenario, never by id.
        $this->assertSame(['socia_a' => 4000, 'socio_b' => 4000], session('demo.split'));

        $bank = (string) $this->get('/bank')->assertOk()->getContent();
        $this->assertStringContainsString('Reparto conciliado desde el extracto.', $bank);
        $this->assertStringContainsString('Conciliado con Socia Ejemplo A · 2026 · 40,00 €', $bank);
        $this->assertStringContainsString('Conciliado con Socio Ejemplo B · 2026 · 40,00 €', $bank);
        $this->assertStringContainsString('No hay cuotas pendientes a las que asignar este abono.', $bank);

        // Nothing of the split reached the treasury database and the connection came back.
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('bank_movements', 0);
        $this->assertSame('sqlite', DB::getDefaultConnection());
    }

    /** S17: a partial split leaves the real remainder visible on the real screens. */
    public function test_a_partial_split_leaves_the_real_remainder_on_the_real_screens(): void
    {
        $this->enterDemo();
        $form = $this->reconcileForm();

        $this->post($form['action'], [
            'submission_key' => $form['key'],
            'allocations' => [$form['allocations'][0] => '40,00'],
        ])->assertRedirect('/bank');

        $this->assertSame(['socia_a' => 4000], session('demo.split'));

        $this->get('/bank')
            ->assertOk()
            ->assertSee('Quedan 60,00 € por conciliar.')
            ->assertSee('Ya se han conciliado 40,00 €');

        $this->get('/saldos')
            ->assertOk()
            ->assertSeeInOrder(['Sin conciliar', '60,00 €'])
            ->assertSeeInOrder(['Por cobrar', '40,00 €']);
    }

    /** S17 and V8: the real balances of the split appear on the real Saldos screen. */
    public function test_the_demo_shows_the_real_balances_of_the_split(): void
    {
        $this->enterDemo();
        $form = $this->reconcileForm();

        $this->post($form['action'], [
            'submission_key' => $form['key'],
            'allocations' => [$form['allocations'][0] => '40,00', $form['allocations'][1] => '40,00'],
        ])->assertRedirect('/bank');

        $this->get('/saldos')
            ->assertOk()
            ->assertSee('<h1>Saldos</h1>', false)
            ->assertSeeInOrder(['Saldo en el banco', '100,00 €'])
            ->assertSeeInOrder(['Sin conciliar', '20,00 €', '1 abono sin conciliar'])
            ->assertSeeInOrder(['Movimientos', '1'])
            ->assertSee('Cuota de 2026')
            ->assertSeeInOrder(['Por cobrar', '0,00 €'])
            ->assertSeeInOrder(['Pendientes', '0 cuotas pendientes']);

        $this->assertDatabaseCount('payments', 0);
    }

    /** S17: a split that does not fit keeps the real message and announces no success. */
    public function test_a_split_that_does_not_fit_keeps_the_real_message(): void
    {
        $this->enterDemo();
        $form = $this->reconcileForm();

        $this->post($form['action'], [
            'submission_key' => $form['key'],
            'allocations' => [$form['allocations'][0] => '50,00'],
        ])->assertRedirect('/bank');

        $this->assertArrayNotHasKey('demo.split', session()->all());

        $this->get('/bank')
            ->assertOk()
            ->assertSee('El pago supera lo pendiente de la cuota (40,00 €).')
            ->assertDontSee('Reparto conciliado desde el extracto.');

        $this->assertDatabaseCount('payments', 0);
    }

    /** S17: the demo split uses the same validation messages as the real form. */
    public function test_the_demo_split_uses_the_real_validation_messages(): void
    {
        $this->enterDemo();
        $form = $this->reconcileForm();

        $this->post($form['action'], [
            'submission_key' => $form['key'],
            'allocations' => [$form['allocations'][0] => ''],
        ])->assertRedirect('/bank');

        $this->get('/bank')
            ->assertOk()
            ->assertSee('Indica el importe de al menos una cuota.')
            ->assertDontSee('Reparto conciliado desde el extracto.');

        $this->post($form['action'], [
            'submission_key' => 'no-es-un-uuid',
            'allocations' => [$form['allocations'][0] => '40,00'],
        ])->assertRedirect('/bank');

        $this->get('/bank')
            ->assertOk()
            ->assertSee('El formulario ha caducado. Recarga la página e inténtalo de nuevo.');

        $this->assertArrayNotHasKey('demo.split', session()->all());
    }

    /** S20: a request the sandbox cannot serve never falls through to the treasury database. */
    public function test_a_write_the_sandbox_does_not_serve_stays_inside_the_demo(): void
    {
        $this->enterDemo();

        // A real write of the treasury is not part of the demo: it never reaches the real tables.
        $this->get('/members/create')->assertRedirect('/dashboard');
        $this->get('/fees/create')->assertRedirect('/dashboard');
        $this->get('/access/two-factor')->assertRedirect('/dashboard');

        $this->assertDatabaseCount('members', 0);
        $this->assertDatabaseCount('fee_years', 0);
        $this->assertDatabaseCount('users', 0);
    }
}
