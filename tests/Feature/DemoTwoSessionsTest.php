<?php

namespace Tests\Feature;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuthTestCase;

/**
 * V8: two demo visitors with database sessions never share their split, and walking the demo
 * changes nothing of the real reports.
 *
 * Each browser is a session id of its own: the middleware forgets drivers between requests so the
 * stored session is read back, exactly as a separate visitor would.
 */
class DemoTwoSessionsTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    private function browser(string $id): void
    {
        app('session')->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance('auth.driver');
        Auth::forgetGuards();

        $this->withCookie(config('session.cookie'), $id);
    }

    public function test_demo_sessions_do_not_share_state_or_change_real_reports(): void
    {
        $real = str_repeat('R', 40);
        $a = str_repeat('A', 40);
        $b = str_repeat('B', 40);

        $this->account('treasury@fixture.test', 'treasury', 'a-fixture-passphrase', 'Real fixture');

        $this->browser($real);
        $this->attemptLogin('treasury@fixture.test', 'a-fixture-passphrase')->assertRedirect('/dashboard');

        $real = session()->getId();

        $before = [];
        foreach (['/members', '/bank', '/saldos'] as $url) {
            $this->browser($real);
            $before[$url] = $this->get($url)->assertOk()->getContent();
        }

        $this->browser($a);
        $this->post('/demo/entrar')->assertRedirect('/dashboard');

        $this->browser($b);
        $this->post('/demo/entrar')->assertRedirect('/dashboard');

        $this->browser($a);
        $bank = (string) $this->get('/bank')->assertOk()->getContent();
        preg_match('~action="(/bank/movements/\d+/reconcile)"~', $bank, $action);
        preg_match('~name="submission_key" value="([^"]+)"~', $bank, $key);
        preg_match_all('~name="allocations\[(\d+)\]"~', $bank, $dues);

        $this->browser($a);
        $this->post($action[1], [
            'submission_key' => $key[1],
            'allocations' => array_fill_keys($dues[1], '40,00'),
        ])->assertRedirect('/bank');

        $this->browser($b);
        $this->get('/bank')->assertOk()->assertSee('Quedan 100,00 € por conciliar.');

        $this->browser($a);
        $this->get('/saldos')->assertOk()->assertSeeInOrder(['Sin conciliar', '20,00 €']);

        // The real treasury reports of the signed-in user are byte for byte what they were.
        foreach ($before as $url => $html) {
            $this->browser($real);
            $this->assertSame($html, $this->get($url)->assertOk()->getContent());
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseEmpty('bank_movements');
        $this->assertDatabaseEmpty('payments');

        // Neither demo session is stored as authenticated.
        foreach ([$a, $b] as $id) {
            $row = DB::table('sessions')->find($id);

            $this->assertNotNull($row);
            $this->assertNull($row->user_id);

            $payload = json_decode(base64_decode($row->payload), true, 512, JSON_THROW_ON_ERROR);
            $this->assertFalse(Arr::has($payload, Auth::guard('web')->getName()));
        }
    }
}
