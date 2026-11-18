<?php

namespace Tests\Feature;

use App\Modules\Access\Models\User;
use App\Modules\Access\Support\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\AuthTestCase;

class TwoFactorTest extends AuthTestCase
{
    /**
     * The ASCII secret "12345678901234567890" of the RFC 6238 appendix, Base32 encoded.
     */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private const GENERIC_CODE_ERROR = 'El código no es válido.';

    public function test_rfc_6238_sha1_vectors_with_eight_digits(): void
    {
        $vectors = [
            59 => '94287082',
            1111111109 => '07081804',
            1111111111 => '14050471',
            1234567890 => '89005924',
            2000000000 => '69279037',
            20000000000 => '65353130',
        ];

        $totp = new Totp;

        foreach ($vectors as $timestamp => $expected) {
            $this->assertSame($expected, $totp->code(self::RFC_SECRET, $timestamp, 8), "T={$timestamp}");
        }
    }

    public function test_generated_secrets_are_base32_and_verify_their_own_code(): void
    {
        $totp = new Totp;
        $secret = $totp->generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $valid = $totp->code($secret, 1700000000);
        $this->assertTrue($totp->verify($secret, $valid, 1700000000));
        $this->assertFalse($totp->verify($secret, $this->wrongCode($secret, 1700000000), 1700000000));
    }

    public function test_enrollment_confirmation_and_login_challenge(): void
    {
        $user = $this->account('ana@example.test', 'treasury', 'correct-password');

        $this->actingAs($user)->get('/access/two-factor')
            ->assertOk()
            ->assertSee('La verificación en dos pasos no está activada.');

        $this->post('/access/two-factor')->assertRedirect('/access/two-factor');
        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);

        $secret = (string) $user->two_factor_secret;
        $this->get('/access/two-factor')
            ->assertOk()
            ->assertSee($secret)
            ->assertSee('otpauth://totp/', false);

        $this->post('/access/two-factor/confirm', ['code' => $this->code($secret)])
            ->assertRedirect('/access/two-factor');
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
        $this->get('/access/two-factor')->assertOk()->assertSee('La verificación en dos pasos está activada.');

        $this->post('/logout');
        $guestSession = session()->getId();

        $this->attemptLogin('ana@example.test', 'correct-password')
            ->assertRedirect('/login/segundo-factor');
        $this->assertGuest();
        $this->assertSame($user->id, session('two_factor.pending_user_id'));
        $this->assertIsInt(session('two_factor.expires_at'));
        $this->assertEqualsWithDelta(now()->addMinutes(5)->getTimestamp(), session('two_factor.expires_at'), 2);

        $this->get('/login/segundo-factor')
            ->assertOk()
            ->assertSee('código de 6 dígitos');

        $this->post('/login/segundo-factor', ['code' => $this->code($secret)])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($guestSession, session()->getId());
        $this->assertNull(session('two_factor.pending_user_id'));
    }

    public function test_a_challenge_expires_exactly_at_the_five_minute_boundary(): void
    {
        $user = $this->account('ana@example.test', 'treasury', 'correct-password');
        $this->enableTwoFactor($user);
        $this->post('/logout');

        $this->attemptLogin('ana@example.test', 'correct-password')->assertRedirect('/login/segundo-factor');

        // Exactly at the boundary the pending login is already expired, not still valid.
        $this->withSession(['two_factor.expires_at' => now()->getTimestamp()]);

        $this->get('/login/segundo-factor')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_a_wrong_code_at_the_challenge_keeps_the_visitor_as_guest(): void
    {
        $user = $this->account('ana@example.test', 'board', 'correct-password');
        $secret = $this->enableTwoFactor($user);
        $this->post('/logout');

        $this->attemptLogin('ana@example.test', 'correct-password')
            ->assertRedirect('/login/segundo-factor');
        $this->assertGuest();

        $this->from('/login/segundo-factor')
            ->post('/login/segundo-factor', ['code' => $this->wrongCode($secret)])
            ->assertRedirect('/login/segundo-factor')
            ->assertSessionHasErrors(['code' => self::GENERIC_CODE_ERROR]);
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_an_expired_challenge_keeps_the_visitor_as_guest(): void
    {
        $user = $this->account('ana@example.test', 'board', 'correct-password');
        $secret = $this->enableTwoFactor($user);
        $this->post('/logout');

        $this->attemptLogin('ana@example.test', 'correct-password')
            ->assertRedirect('/login/segundo-factor');

        $this->travel(301)->seconds();

        $this->from('/login/segundo-factor')
            ->post('/login/segundo-factor', ['code' => $this->code($secret)])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertNull(session('two_factor.pending_user_id'));
    }

    public function test_code_attempts_are_limited_to_five_per_minute(): void
    {
        $user = $this->account('ana@example.test', 'board', 'correct-password');
        $secret = $this->enableTwoFactor($user);
        $this->post('/logout');

        $this->attemptLogin('ana@example.test', 'correct-password')
            ->assertRedirect('/login/segundo-factor');

        $wrong = $this->wrongCode($secret);
        for ($i = 0; $i < 5; $i++) {
            $this->from('/login/segundo-factor')
                ->post('/login/segundo-factor', ['code' => $wrong])
                ->assertSessionHasErrors(['code' => self::GENERIC_CODE_ERROR]);
        }

        $this->post('/login/segundo-factor', ['code' => $this->code($secret)])
            ->assertSessionHasErrors(['code' => self::GENERIC_CODE_ERROR]);
        $this->assertGuest();

        $this->travel(61)->seconds();
        $this->post('/login/segundo-factor', ['code' => $this->code($secret)])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_secret_is_encrypted_at_rest(): void
    {
        $user = $this->account('ana@example.test', 'board', 'correct-password');
        $this->actingAs($user)->post('/access/two-factor')->assertRedirect('/access/two-factor');

        $secret = (string) $user->fresh()->two_factor_secret;
        $raw = (string) DB::table('users')->where('id', $user->id)->value('two_factor_secret');

        $this->assertNotSame('', $secret);
        $this->assertNotSame($secret, $raw);
        $this->assertStringNotContainsString($secret, $raw);
    }

    public function test_disabling_requires_a_valid_code_and_keeps_the_factor_when_it_is_wrong(): void
    {
        $user = $this->account('ana@example.test', 'treasury', 'correct-password');
        $secret = $this->enableTwoFactor($user);

        $this->from('/access/two-factor')
            ->post('/access/two-factor/disable', ['code' => $this->wrongCode($secret)])
            ->assertRedirect('/access/two-factor')
            ->assertSessionHasErrors(['code' => self::GENERIC_CODE_ERROR]);

        $user->refresh();
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertNotNull($user->two_factor_secret);

        $this->post('/access/two-factor/disable', ['code' => $this->code($secret)])
            ->assertRedirect('/access/two-factor');

        $user->refresh();
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNull($user->two_factor_secret);
    }

    public function test_an_unconfirmed_secret_does_not_block_login(): void
    {
        $user = $this->account('ana@example.test', 'board', 'correct-password');
        $this->actingAs($user)->post('/access/two-factor')->assertRedirect('/access/two-factor');
        $this->post('/logout');

        $this->attemptLogin('ana@example.test', 'correct-password')->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_challenge_page_is_not_reachable_without_a_pending_login(): void
    {
        $this->get('/login/segundo-factor')->assertRedirect('/login');
    }

    public function test_confirm_and_disable_share_a_code_limiter(): void
    {
        $user = $this->account('ana@example.test', 'treasury', 'correct-password');
        $secret = $this->enableTwoFactor($user);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/access/two-factor/disable', ['code' => $this->wrongCode($secret)])
                ->assertSessionHasErrors(['code' => self::GENERIC_CODE_ERROR]);
        }

        // The sixth attempt is rejected even with the right code, and the factor stays on.
        $this->post('/access/two-factor/disable', ['code' => $this->code($secret)])
            ->assertSessionHasErrors(['code' => self::GENERIC_CODE_ERROR]);

        $user->refresh();
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertNotNull($user->two_factor_secret);
    }

    public function test_the_pending_password_submission_upgrades_an_outdated_hash(): void
    {
        $user = $this->account('ana@example.test', 'treasury', 'correct-password');
        $this->enableTwoFactor($user);
        $this->post('/logout');

        // A hash written when the cost was lower, straight through the database so the
        // hashed cast does not re-hash it on the way in.
        DB::table('users')->where('id', $user->id)
            ->update(['password' => Hash::make('correct-password', ['rounds' => 4])]);
        config(['hashing.bcrypt.rounds' => 8]);
        app('hash')->forgetDrivers();

        $this->attemptLogin('ana@example.test', 'correct-password')->assertRedirect('/login/segundo-factor');
        $this->assertGuest();

        $upgraded = (string) $user->fresh()->password;
        $this->assertStringStartsWith('$2y$08$', $upgraded);
        $this->assertTrue(Hash::check('correct-password', $upgraded));
    }

    /**
     * Complete the real enrollment flow and leave the user signed in.
     */
    private function enableTwoFactor(User $user): string
    {
        $this->actingAs($user)->post('/access/two-factor')->assertRedirect('/access/two-factor');

        $secret = (string) $user->fresh()->two_factor_secret;
        $this->post('/access/two-factor/confirm', ['code' => $this->code($secret)])
            ->assertRedirect('/access/two-factor');
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);

        return $secret;
    }

    private function code(string $secret, ?int $now = null): string
    {
        return (new Totp)->code($secret, $now ?? time());
    }

    private function wrongCode(string $secret, ?int $now = null): string
    {
        return str_pad((string) (((int) $this->code($secret, $now) + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);
    }
}
