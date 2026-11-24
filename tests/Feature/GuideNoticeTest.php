<?php

namespace Tests\Feature;

use App\Modules\Access\Models\User;
use App\Modules\Bank\Models\BankImport;
use App\Modules\Bank\Models\BankMovement;
use App\Modules\Demo\Support\GuideSteps;
use App\Modules\Fees\Actions\SetFeeYear;
use App\Modules\Members\Models\Member;
use Illuminate\Support\Carbon;
use Tests\Support\AuthTestCase;

/**
 * Issue #74, T9 and T10 (S16–S19, S22 V2–V7): the guide is an in-place notice on the real screens.
 *
 * Every notice is three short sentences from a single copy source, it names the real control with
 * the label that control has today, it is not a dialog, it closes and repeats without JavaScript
 * and without changing the URL, and a real treasury session sees none of it.
 */
class GuideNoticeTest extends AuthTestCase
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

    /** The entry of the demo session: an explicit POST with the session token, no credentials. */
    private function enterDemo(): void
    {
        $this->post('/demo/entrar')->assertRedirect('/dashboard');
    }

    private function html(string $url): string
    {
        return (string) $this->get($url)->assertOk()->getContent();
    }

    /** The rendered notice of one step, asserting the screen carries exactly one of them. */
    private function notice(string $html, string $step): string
    {
        $this->assertSame(1, substr_count($html, 'class="guide"'), 'la pantalla muestra un solo aviso');
        $this->assertSame(
            1,
            preg_match('/<details class="guide"[^>]*data-guide="'.$step.'"[^>]*>(.*?)<\/details>/s', $html, $matches),
            'la pantalla muestra el aviso del paso «'.$step.'»',
        );

        return $matches[0];
    }

    /** The copy of a step: exactly three sentences, at most thirty words. */
    private function assertShortCopy(string $copy): void
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($copy), -1, PREG_SPLIT_NO_EMPTY);

        $this->assertCount(3, $sentences, 'el aviso tiene exactamente tres frases: '.$copy);
        $this->assertLessThanOrEqual(
            30,
            count(preg_split('/\s+/u', trim($copy), -1, PREG_SPLIT_NO_EMPTY)),
            'el aviso no pasa de treinta palabras: '.$copy,
        );
    }

    /** The real reconcile form of the page, read from its own markup. */
    private function reconcileForm(string $html): string
    {
        $this->assertSame(
            1,
            preg_match('/<form[^>]*action="(\/bank\/movements\/\d+\/reconcile)"[^>]*>(.*?)<\/form>/s', $html, $matches),
            'la pantalla muestra un único formulario real de conciliación',
        );

        return $matches[0];
    }

    private function treasury(): User
    {
        return $this->account('tesoreria@example.test', 'treasury', 'treasury-secret-value', 'Ana Ficticia');
    }

    private function seedTreasury(): void
    {
        foreach (['ana@example.test' => 'Ana ficticia', 'luis@example.test' => 'Luis ficticio'] as $email => $name) {
            Member::create(['name' => $name, 'email' => $email, 'joined_on' => '2026-01-01']);
        }

        app(SetFeeYear::class)->handle(2026, 4000);
    }

    private function credit(User $author, int $cents = 10000, string $concept = 'TRANSFERENCIA DE EJEMPLO'): BankMovement
    {
        $import = BankImport::forceCreate([
            'user_id' => $author->id,
            'imported_count' => 1,
            'duplicate_count' => 0,
            'rejected_count' => 0,
        ]);

        return BankMovement::forceCreate([
            'bank_import_id' => $import->id,
            'occurred_on' => '2026-05-21',
            'amount_cents' => $cents,
            'concept' => $concept,
            'fingerprint' => hash('sha256', $concept.'|'.$cents),
        ]);
    }

    /** S18 and V4: the panel's step is three sentences from the single copy source. */
    public function test_the_demo_panel_shows_the_first_step_of_the_shared_copy(): void
    {
        $this->enterDemo();

        $html = $this->html('/dashboard');
        $notice = $this->notice($html, GuideSteps::PANEL);

        $copy = GuideSteps::notice(GuideSteps::PANEL);
        $this->assertStringContainsString($copy, $notice, 'el aviso es el texto de GuideSteps, no una copia suelta');
        $this->assertShortCopy($copy);

        // The step is anchored to the real link of the next screen.
        $this->assertStringContainsString('Movimientos bancarios', $notice);
        $this->assertStringContainsString('<b>Movimientos bancarios</b>', $html, 'el enlace real sigue en la pantalla');
    }

    /** S17 and V3: the bank's step sits in the block of the real control and names its label. */
    public function test_the_demo_bank_shows_the_second_step_next_to_the_real_control(): void
    {
        $this->enterDemo();

        $html = $this->html('/bank');
        $notice = $this->notice($html, GuideSteps::BANK);
        $form = $this->reconcileForm($html);

        $copy = GuideSteps::notice(GuideSteps::BANK);
        $this->assertStringContainsString($copy, $notice);
        $this->assertShortCopy($copy);
        $this->assertStringContainsString('Conciliar el reparto', $notice);

        // The notice is inside the same article as the control, after it, and never wraps it.
        $action = (int) strpos($html, 'action="/bank/movements/');
        $articleStart = (int) strrpos(substr($html, 0, $action), '<article class="entry movement">');
        $articleEnd = (int) strpos($html, '</article>', $action);
        $noticeAt = (int) strpos($html, 'data-guide="'.GuideSteps::BANK.'"');

        $this->assertGreaterThan($articleStart, $noticeAt, 'el aviso está en el bloque del control real');
        $this->assertLessThan($articleEnd, $noticeAt, 'el aviso está en el bloque del control real');

        // V5: one real form, its real action and a single submission_key.
        preg_match('/action="([^"]+)"/', $form, $match);
        $this->assertSame(1, substr_count($html, 'action="'.$match[1].'"'), 'un solo formulario apunta a la acción real');
        $this->assertSame(1, substr_count($form, 'name="submission_key"'));
    }

    /** S17: the balances' step sits next to the real figures of the screen. */
    public function test_the_demo_balances_show_the_third_step_next_to_the_real_figures(): void
    {
        $this->enterDemo();

        $html = $this->html('/saldos');
        $notice = $this->notice($html, GuideSteps::SALDOS);

        $copy = GuideSteps::notice(GuideSteps::SALDOS);
        $this->assertStringContainsString($copy, $notice);
        $this->assertShortCopy($copy);
        $this->assertStringContainsString('Sin conciliar', $notice);
        $this->assertStringContainsString('Por cobrar', $notice);

        // The notice follows the bank figures of the real screen and stays before the cash block.
        $this->assertGreaterThan(
            (int) strpos($html, 'aria-label="Banco"'),
            (int) strpos($html, 'data-guide="'.GuideSteps::SALDOS.'"'),
        );
        $this->assertLessThan(
            (int) strpos($html, 'aria-label="Caja"'),
            (int) strpos($html, 'data-guide="'.GuideSteps::SALDOS.'"'),
        );
    }

    /** The labels the guide uses are the labels the real views render today (no invented copy). */
    public function test_the_guide_names_the_labels_the_real_views_render_today(): void
    {
        $sources = [
            GuideSteps::PANEL => ['Movimientos bancarios' => 'resources/views/dashboard.blade.php'],
            GuideSteps::BANK => ['Conciliar el reparto' => 'resources/views/components/reconcile-form.blade.php'],
            GuideSteps::SALDOS => [
                'Sin conciliar' => 'resources/views/components/bank-kpis.blade.php',
                'Por cobrar' => 'resources/views/summary.blade.php',
            ],
        ];

        foreach ($sources as $step => $labels) {
            $copy = GuideSteps::notice($step);

            foreach ($labels as $label => $view) {
                $this->assertStringContainsString($label, $copy, 'el paso nombra «'.$label.'»');
                $this->assertStringContainsString(
                    $label,
                    (string) file_get_contents(base_path($view)),
                    '«'.$label.'» sigue en la vista real',
                );
            }
        }
    }

    /** S19 and V2: the notice is not a dialog and never navigates. */
    public function test_the_notice_is_not_a_dialog_and_never_navigates(): void
    {
        $this->enterDemo();

        foreach (['/dashboard', '/bank', '/saldos'] as $url) {
            $html = $this->html($url);

            $this->assertStringNotContainsString('role="dialog"', $html);
            $this->assertStringNotContainsString('aria-modal', $html);
            $this->assertStringNotContainsString('overflow: hidden', $html);
            $this->assertStringNotContainsString('overflow:hidden', $html);
            $this->assertStringNotContainsString('http-equiv="refresh"', $html);
            $this->assertStringNotContainsString('<meta http-equiv', $html);
            $this->assertStringNotContainsString('tabindex="-1"', $this->notice($html, $url === '/dashboard' ? GuideSteps::PANEL : ($url === '/bank' ? GuideSteps::BANK : GuideSteps::SALDOS)));
        }
    }

    /** S19 and V6: it closes and repeats on the same screen, also without JavaScript. */
    public function test_the_notice_closes_and_repeats_without_navigating_or_javascript(): void
    {
        $this->enterDemo();

        $steps = [
            ['/dashboard', GuideSteps::PANEL],
            ['/bank', GuideSteps::BANK],
            ['/saldos', GuideSteps::SALDOS],
        ];

        foreach ($steps as [$url, $step]) {
            $notice = $this->notice($this->html($url), $step);

            // A native disclosure: it is open, it closes with mouse and keyboard and it reopens.
            $this->assertStringContainsString('<details class="guide" open', $notice);
            $this->assertStringContainsString('<summary', $notice);
            $this->assertStringContainsString('Cerrar la guía', $notice);
            $this->assertStringContainsString('Ver la guía otra vez', $notice);

            // Nothing inside the notice can navigate, post or change the URL.
            $this->assertStringNotContainsString('<a ', $notice);
            $this->assertStringNotContainsString('href=', $notice);
            $this->assertStringNotContainsString('<form', $notice);
            $this->assertStringNotContainsString('<script', $notice);
            $this->assertStringNotContainsString('<meta', $notice);
        }
    }

    /** V9: a real treasury session sees no guide and keeps every real control it has today. */
    public function test_a_real_treasury_session_sees_no_guide_and_keeps_its_controls(): void
    {
        $treasury = $this->treasury();

        // The real empty bank screen still offers its real file link and its real write forms.
        $emptyBank = (string) $this->actingAs($treasury)->get('/bank')->assertOk()->getContent();
        $this->assertStringContainsString('href="#file"', $emptyBank);
        $this->assertStringNotContainsString('data-guide=', $emptyBank);

        $this->seedTreasury();
        $movement = $this->credit($treasury);

        foreach (['/dashboard', '/bank', '/saldos'] as $url) {
            $html = (string) $this->actingAs($treasury)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('data-guide=', $html);
            $this->assertStringNotContainsString('class="guide"', $html);
            $this->assertStringNotContainsString('Cerrar la guía', $html);
            $this->assertStringNotContainsString('Ver la guía otra vez', $html);
            $this->assertStringNotContainsString('demo-mode', $html);
        }

        $bank = (string) $this->actingAs($treasury)->get('/bank')->assertOk()->getContent();
        $this->assertStringContainsString('action="/bank/imports"', $bank);
        $this->assertStringContainsString('Importar extracto', $bank);
        $this->assertStringContainsString('Buscar las cuotas pendientes de un socio', $bank);
        $this->assertStringContainsString('action="/bank/movements/'.$movement->id.'/reconcile"', $bank);
        $this->assertStringContainsString('action="/bank/movements/'.$movement->id.'/incomes"', $bank);

        // The real year link of /saldos and the real security link of the shell are untouched.
        $saldos = (string) $this->actingAs($treasury)->get('/saldos')->assertOk()->getContent();
        $this->assertStringContainsString('href="/fees/', $saldos);

        $dashboard = (string) $this->actingAs($treasury)->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('href="/access/two-factor"', $dashboard);
    }

    /** The demo never shows a control that would bounce it back to /dashboard. */
    public function test_the_demo_hides_the_controls_the_demo_bounces(): void
    {
        $this->enterDemo();

        $bank = $this->html('/bank');
        $this->assertStringNotContainsString('action="/bank/imports"', $bank);
        $this->assertStringNotContainsString('Importar extracto', $bank);
        $this->assertStringNotContainsString('href="#file"', $bank);
        $this->assertStringNotContainsString('Clasificar como otro ingreso', $bank);
        $this->assertStringNotContainsString('/incomes"', $bank);

        $dashboard = $this->html('/dashboard');
        $this->assertStringNotContainsString('href="/access/two-factor"', $dashboard);

        $saldos = $this->html('/saldos');
        $this->assertStringNotContainsString('href="/fees/', $saldos);
        $this->assertStringContainsString('Cuota de 2026', $saldos);

        // After a split the demo does not offer to void the parts it just reconciled.
        $form = $this->reconcileForm($bank);
        preg_match('/action="([^"]+)"/', $form, $action);
        preg_match('/name="submission_key" value="([^"]+)"/', $form, $key);
        preg_match_all('/name="allocations\[(\d+)\]"/', $form, $ids);

        $this->post($action[1], [
            'submission_key' => $key[1],
            'allocations' => [$ids[1][0] => '40,00', $ids[1][1] => '40,00'],
        ])->assertRedirect('/bank');

        $after = $this->html('/bank');
        $this->assertStringContainsString('Reparto conciliado desde el extracto.', $after);
        $this->assertStringContainsString('Conciliado con Socia Ejemplo A', $after);
        $this->assertStringNotContainsString('/payments/', $after);
        $this->assertStringNotContainsString('Anular y liberar', $after);
    }

    /**
     * L17–L18 leftover: the demo also hides the member links its middleware answers with 302.
     *
     * The demo walks /members, but creating a member is a real write the sandbox does not serve,
     * so neither of the two entries of the real screen may be offered there. A real treasury
     * session keeps both of them exactly as today.
     */
    public function test_the_demo_hides_the_member_create_links_and_a_real_session_keeps_them(): void
    {
        $this->enterDemo();

        $demo = $this->html('/members');
        $this->assertStringContainsString('Socia Ejemplo A', $demo, 'la demo recorre la pantalla real de socios');
        $this->assertStringNotContainsString('/members/create', $demo);
        $this->assertStringNotContainsString('Nuevo socio', $demo);
        $this->assertStringNotContainsString('Añadir un socio', $demo);

        // PRESERVE: with no members, the real screen shows both entries it shows today.
        $real = (string) $this->actingAs($this->treasury())->get('/members')->assertOk()->getContent();
        $this->assertSame(2, substr_count($real, 'href="/members/create"'), 'la pantalla real conserva sus dos enlaces');
        $this->assertStringContainsString('Nuevo socio', $real);
        $this->assertStringContainsString('Añadir un socio', $real);
    }

    /** T10: the remainder of the real control lives in a polite output, fields untouched. */
    public function test_the_remainder_of_the_real_control_is_a_polite_output(): void
    {
        $this->enterDemo();

        $bank = $this->html('/bank');
        $form = $this->reconcileForm($bank);

        $this->assertStringContainsString(
            '<output class="notice linked" aria-live="polite">Quedan 100,00 € por conciliar.</output>',
            $bank,
        );
        $this->assertStringContainsString('<output', $form);
        $this->assertStringContainsString('aria-live="polite"', $form);

        // The real field names and the single submission_key do not change.
        $this->assertSame(2, preg_match_all('/name="allocations\[\d+\]"/', $form));
        $this->assertSame(1, substr_count($form, 'name="submission_key"'));
        $this->assertStringContainsString('Conciliar el reparto', $form);
    }

    /** PRESERVE: the shared component keeps the real /bank controls of a treasury session. */
    public function test_the_real_bank_screen_keeps_its_reconcile_controls(): void
    {
        $treasury = $this->treasury();
        $this->seedTreasury();
        $movement = $this->credit($treasury, 2000, 'TRANSFERENCIA ANA');

        $bank = (string) $this->actingAs($treasury)->get('/bank')->assertOk()->getContent();
        $form = $this->reconcileForm($bank);

        $this->assertStringContainsString('action="/bank/movements/'.$movement->id.'/reconcile"', $form);
        $this->assertStringContainsString('name="submission_key"', $form);
        $this->assertStringContainsString('name="allocations[', $form);
        $this->assertStringContainsString('id="split-'.$movement->id.'-', $form);
        $this->assertStringContainsString('<label for="split-'.$movement->id.'-', $form);
        $this->assertStringContainsString('<p class="notice linked">Quedan 20,00 € por conciliar.</p>', $form);
        $this->assertStringNotContainsString('<output', $form, 'la pantalla real conserva su marcado');
        $this->assertStringNotContainsString('aria-live', $form);
        $this->assertStringContainsString('Escribe el importe de cada cuota que pague este abono.', $form);
        $this->assertStringContainsString('Conciliar el reparto', $form);
    }
}
