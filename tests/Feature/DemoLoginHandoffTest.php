<?php

namespace Tests\Feature;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuthTestCase;

/**
 * H2: a real login drops the demo mark and the stored split at the moment the real identity is
 * established, before the session is regenerated and stored.
 */
class DemoLoginHandoffTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    public function test_demo_mark_is_not_persisted_by_real_login(): void
    {
        $user = $this->account('real@fixture.test', 'treasury', 'a-fixture-passphrase', 'Real fixture');

        $this->withSession(['demo.session' => true, 'demo.split' => ['socia_a' => 4000]]);

        $this->attemptLogin('real@fixture.test', 'a-fixture-passphrase')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        $row = DB::table('sessions')->find(session()->getId());

        $this->assertNotNull($row);

        $payload = json_decode(base64_decode($row->payload), true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse(Arr::has($payload, 'demo.session'));
        $this->assertFalse(Arr::has($payload, 'demo.split'));
        // The real login key is there: the demo state was dropped, not the authentication.
        $this->assertTrue(Arr::has($payload, Auth::guard('web')->getName()));
    }
}
