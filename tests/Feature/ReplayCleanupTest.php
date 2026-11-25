<?php

namespace Tests\Feature;

use App\Modules\Bank\Actions\ReconcileMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\AuthTestCase;

/**
 * H1: a failing replay never leaves the example actor on the guard.
 *
 * The actor of the sandbox is installed on the guard for the lifetime of the request, and the
 * framework copies the id of the guard into `sessions.user_id` when the request saves its
 * session. So installing the actor and replaying the stored split have to happen inside the
 * cleanup boundary: a replay that throws must leave no user id in the stored session either.
 */
class ReplayCleanupTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The damage only shows with sessions stored in the database: with the array driver
        // nothing is ever written, so the stored row is what the finding is about.
        config(['session.driver' => 'database']);
    }

    public function test_replay_failure_does_not_leave_the_actor_on_the_guard(): void
    {
        $this->withSession(['demo.session' => true, 'demo.split' => ['socia_a' => 4000]]);

        $this->mock(ReconcileMovement::class)
            ->shouldReceive('handle')
            ->once()
            ->andThrow(new RuntimeException('Injected replay failure'));

        $this->get('/dashboard')->assertStatus(500);

        $this->assertSame('sqlite', DB::getDefaultConnection());
        $this->assertNull(session(Auth::guard('web')->getName()), 'el actor de ejemplo no queda en la sesión de la petición');

        $row = DB::table('sessions')->find(session()->getId());

        $this->assertNotNull($row, 'la petición fallida guarda su sesión');
        $this->assertNull($row->user_id, 'ningún id del actor de ejemplo llega a la sesión guardada');

        $this->assertGuest();
    }
}
