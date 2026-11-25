<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuthTestCase;

/**
 * Issue #73: the public guided demo reconciles a fictitious 100,00 € credit into two 40,00 €
 * dues and leaves 20,00 € pending. It runs the real actions against a disposable in-memory
 * database, never against the treasury database, and it keeps no state between visitors.
 */
class DemoTest extends AuthTestCase
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

    private function assertMainTreasuryUntouched(): void
    {
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('members', 0);
        $this->assertDatabaseCount('fee_years', 0);
        $this->assertDatabaseCount('dues', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('bank_imports', 0);
        $this->assertDatabaseCount('bank_movements', 0);
    }

    /** S8: a rejected request is rendered in place and never flashed to the session. */
    private function assertNoRequestWasPersisted(): void
    {
        $this->assertArrayNotHasKey('_old_input', session()->all());
        $this->assertArrayNotHasKey('errors', session()->all());
    }

    /** S4, S5, S9, S11: the public page shows the fixed scenario and the guided two first steps. */
    public function test_the_public_page_shows_the_fixed_scenario_and_the_guided_steps(): void
    {
        $response = $this->get('/demo');

        $response->assertOk()
            ->assertSee('Qué vas a probar')
            ->assertSee('Concilia el abono')
            ->assertSee('Conciliar 80 €')
            ->assertSee('Socia Ejemplo A')
            ->assertSee('socia.a@ejemplo.test')
            ->assertSee('Socio Ejemplo B')
            ->assertSee('socio.b@ejemplo.test')
            ->assertSeeInOrder(['40,00 €', 'Socia Ejemplo A', '40,00 €', 'Socio Ejemplo B'])
            ->assertSee('TRANSFERENCIA DE EJEMPLO')
            ->assertSee('100,00 €')
            ->assertSee('21/05/2026')
            ->assertSee('2026')
            ->assertSee('Las operaciones de este ejemplo son reales, pero ocurren en una base de datos desechable que no guarda nada al terminar.')
            ->assertSee('No crea ninguna cuenta y no toca la tesorería real ni los datos de otros visitantes.')
            // The form has associated labels and prefilled amounts; it works without JavaScript.
            ->assertSee('name="allocations[socia_a]"', false)
            ->assertSee('name="allocations[socio_b]"', false)
            ->assertSee('id="allocations-socia_a"', false)
            ->assertSee('id="allocations-socio_b"', false)
            ->assertSee('<label for="allocations-socia_a"', false)
            ->assertSee('<label for="allocations-socio_b"', false)
            ->assertSee('value="40,00"', false)
            ->assertSee('method="post"', false)
            ->assertSee('action="/demo/conciliar"', false)
            // No shortcut into the existing signed-in panel.
            ->assertDontSee('href="/dashboard"', false)
            ->assertDontSee('href="/bank"', false)
            ->assertDontSee('href="/members"', false)
            ->assertDontSee('href="/saldos"', false);

        // A first visit is step one and two only: there is no result to show yet.
        $response->assertDontSee('Reparto conciliado desde el extracto.');

        $this->assertMainTreasuryUntouched();
        $this->assertNoRequestWasPersisted();
        $this->assertSame('sqlite', DB::getDefaultConnection());
    }

    /** S6, S7, S10: the real reconciliation creates two payments and the real summary shows 20,00 € left. */
    public function test_reconciling_eighty_euros_shows_the_real_two_payments_and_the_real_summary(): void
    {
        $response = $this->post('/demo/conciliar', [
            'allocations' => ['socia_a' => '40,00', 'socio_b' => '40,00'],
        ]);

        $response->assertOk()
            ->assertSee('Reparto conciliado desde el extracto.')
            ->assertSee('Resultado')
            // Each payment is attributed to its member and fee year.
            ->assertSeeInOrder(['Socia Ejemplo A', '2026', '40,00 €'])
            ->assertSeeInOrder(['Socio Ejemplo B', '2026', '40,00 €'])
            // The same labels and figures as /saldos.
            ->assertSeeInOrder(['Saldo en el banco', '100,00 €'])
            ->assertSeeInOrder(['Sin conciliar', '20,00 €', '1 abono sin conciliar'])
            ->assertSeeInOrder(['Movimientos', '1'])
            ->assertSeeInOrder(['Cuota de 2026', 'Por cobrar', '0,00 €', '0 cuotas pendientes'])
            ->assertSee('Los 20,00 € que siguen sin conciliar son el resto del abono, no deuda de cuotas.')
            ->assertSee('Las cuotas de los dos socios quedan pagadas.')
            ->assertSee('Las operaciones de este ejemplo son reales, pero ocurren en una base de datos desechable que no guarda nada al terminar.')
            ->assertSee('No crea ninguna cuenta y no toca la tesorería real ni los datos de otros visitantes.');

        $this->assertMainTreasuryUntouched();
        $this->assertNoRequestWasPersisted();
        $this->assertSame('sqlite', DB::getDefaultConnection());
    }

    /** S7 and S10: the figures are calculated by the real summary, never typed by the view. */
    public function test_the_pending_figure_comes_from_the_real_summary_and_not_from_a_fixed_value(): void
    {
        $this->post('/demo/conciliar', [
            'allocations' => ['socia_a' => '20,00', 'socio_b' => '20,00'],
        ])
            ->assertOk()
            ->assertSee('Reparto conciliado desde el extracto.')
            ->assertSeeInOrder(['Sin conciliar', '60,00 €', '1 abono sin conciliar'])
            ->assertSeeInOrder(['Por cobrar', '40,00 €', '2 cuotas pendientes'])
            ->assertDontSee('Las cuotas de los dos socios quedan pagadas.')
            ->assertSee('Quedan 2 cuotas pendientes: este reparto no cubre las dos cuotas.');

        $this->assertDoesNotMatchRegularExpression(
            '/Sin conciliar.*?20,00 €.*?1 abono sin conciliar/s',
            (string) $this->get('/demo')->assertOk()->getContent(),
        );
    }

    /**
     * S7 and S10: a partial split reports the real remainder and the real due state, so it cannot
     * announce that both dues are paid when one of them is still pending.
     */
    public function test_a_partial_split_shows_the_real_remainder_and_the_real_due_state(): void
    {
        $response = $this->post('/demo/conciliar', [
            'allocations' => ['socia_a' => '40,00', 'socio_b' => '15,50'],
        ]);

        $response->assertOk()
            ->assertSee('Reparto conciliado desde el extracto.')
            ->assertSeeInOrder(['Socia Ejemplo A', '2026', '40,00 €'])
            ->assertSeeInOrder(['Socio Ejemplo B', '2026', '15,50 €'])
            ->assertSeeInOrder(['Sin conciliar', '44,50 €', '1 abono sin conciliar'])
            ->assertSeeInOrder(['Por cobrar', '24,50 €', '1 cuota pendiente'])
            ->assertDontSee('Las cuotas de los dos socios quedan pagadas.')
            ->assertSee('Queda 1 cuota pendiente: este reparto no cubre las dos cuotas.');

        $this->assertMainTreasuryUntouched();
        $this->assertNoRequestWasPersisted();
        $this->assertSame('sqlite', DB::getDefaultConnection());
    }

    /** S10: the real payment rule rejects a line above its due and announces no success. */
    public function test_a_line_above_the_due_is_refused_with_the_real_message_and_no_success(): void
    {
        $response = $this->post('/demo/conciliar', [
            'allocations' => ['socia_a' => '40,00', 'socio_b' => '50,00'],
        ]);

        // Rejected in the response itself: no redirect with the request content, no flashed errors.
        $response->assertOk()
            ->assertSee('El pago supera lo pendiente de la cuota (40,00 €).')
            ->assertDontSee('Reparto conciliado desde el extracto.');

        $this->assertNoRequestWasPersisted();
        $this->assertMainTreasuryUntouched();
        $this->assertSame('sqlite', DB::getDefaultConnection());
    }

    /** S10: malformed amounts use the same validation messages as /bank. */
    public function test_malformed_amounts_use_the_same_messages_as_bank(): void
    {
        $this->post('/demo/conciliar', ['allocations' => ['socia_a' => 'diez', 'socio_b' => '']])
            ->assertOk()
            ->assertSee('Cada importe debe ser mayor que cero, con hasta dos decimales.')
            ->assertDontSee('Reparto conciliado desde el extracto.');

        $this->assertNoRequestWasPersisted();

        $this->post('/demo/conciliar', ['allocations' => ['socia_a' => '', 'socio_b' => '']])
            ->assertOk()
            ->assertSee('Indica el importe de al menos una cuota.');

        $this->assertNoRequestWasPersisted();
        $this->assertMainTreasuryUntouched();
    }

    /** S8: the visitor may only send the two scenario dues, never a database identifier. */
    public function test_inputs_are_restricted_to_the_scenario_dues(): void
    {
        $this->post('/demo/conciliar', ['allocations' => ['999' => '40,00']])
            ->assertOk()
            ->assertSee('Solo puedes conciliar las dos cuotas de este ejemplo.')
            ->assertDontSee('Reparto conciliado desde el extracto.');

        $this->assertNoRequestWasPersisted();

        $this->post('/demo/conciliar', [
            'allocations' => ['socia_a' => '40,00', 'socio_b' => '40,00', 'otra' => '40,00'],
        ])
            ->assertOk()
            ->assertSee('Solo puedes conciliar las dos cuotas de este ejemplo.')
            ->assertDontSee('Reparto conciliado desde el extracto.');

        $this->assertNoRequestWasPersisted();
        $this->assertMainTreasuryUntouched();
    }

    /**
     * S8: no demo row reaches the treasury database, no state survives into the next request and
     * two visitors never see each other's result.
     */
    public function test_the_demo_keeps_no_state_and_leaves_the_real_treasury_untouched(): void
    {
        $this->post('/demo/conciliar', [
            'allocations' => ['socia_a' => '40,00', 'socio_b' => '40,00'],
        ])->assertOk()->assertSee('Reparto conciliado desde el extracto.');

        $this->assertMainTreasuryUntouched();
        $this->assertNoRequestWasPersisted();

        // A second visitor starts from the beginning and writes its own figures.
        $this->get('/demo')
            ->assertOk()
            ->assertSee('Qué vas a probar')
            ->assertDontSee('Resultado')
            ->assertDontSee('Reparto conciliado desde el extracto.');

        $this->post('/demo/conciliar', [
            'allocations' => ['socia_a' => '20,00', 'socio_b' => '20,00'],
        ])
            ->assertOk()
            ->assertSeeInOrder(['Sin conciliar', '60,00 €']);

        $this->assertMainTreasuryUntouched();
        $this->assertNoRequestWasPersisted();

        // The existing panel stays closed and its permissions are not relaxed.
        $this->get('/members')->assertRedirect('/login');
        $this->get('/bank')->assertRedirect('/login');
        $this->get('/saldos')->assertRedirect('/login');
    }

    /** The public endpoint has a bounded cost per visitor. */
    public function test_the_public_demo_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->get('/demo')->assertOk();
        }

        $this->get('/demo')->assertStatus(429);
    }

    /**
     * #74 D1 (S15, S21): the landing keeps one entry, «Entrar a la demo», and the walkthrough
     * link is gone; the demo of #73 stays reachable in the same way as before.
     */
    public function test_the_landing_keeps_a_single_entry_and_no_walkthrough_link(): void
    {
        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertSame(0, substr_count($html, 'Probar Nexo'));
        $this->assertSame(0, substr_count($html, 'href="/practica"'));
        $this->assertSame(2, substr_count($html, 'Entrar a la demo'));
        $this->assertSame(2, substr_count($html, 'href="/login"'));

        // The access screen is where both entries live now: the account and the demo session.
        $this->get('/login')
            ->assertOk()
            ->assertSee('Probar sin cuenta')
            ->assertSee('action="/demo/entrar"', false)
            ->assertDontSee('Probar Nexo')
            ->assertDontSee('href="/practica"', false);
    }
}
