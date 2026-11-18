<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    private const STRICT_CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; base-uri 'none'; frame-ancestors 'none'";

    /** Content of public/hot before the test, so an already running Vite server survives the run. */
    private ?string $hotMarkerBackup = null;

    private bool $hotMarkerExisted = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Redirects touch the session, which is encrypted with the application key.
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

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

    private function writeHotMarker(string $origin): void
    {
        file_put_contents($this->hotMarker(), $origin);
    }

    public function test_public_pages_carry_the_fixed_security_headers(): void
    {
        foreach (['/', '/login'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeader('Content-Security-Policy', self::STRICT_CSP);
        }
    }

    /** Issue #50: redirects and errors are responses too, so they carry the same hardening. */
    public function test_redirected_responses_carry_the_same_headers(): void
    {
        $this->get('/dashboard')
            ->assertRedirect('/login')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy', self::STRICT_CSP);
    }

    public function test_content_security_policy_stays_strict_without_a_hot_marker(): void
    {
        $csp = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertSame(self::STRICT_CSP, $csp);
        $this->assertStringNotContainsString('connect-src', (string) $csp);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function viteDevServerMarkers(): array
    {
        return [
            'ipv6 loopback' => ['http://[::1]:5173', 'http://[::1]:5173', 'ws://[::1]:5173'],
            'ipv4 loopback' => ['http://127.0.0.1:5173', 'http://127.0.0.1:5173', 'ws://127.0.0.1:5173'],
            'localhost without a port' => ['http://localhost', 'http://localhost', 'ws://localhost'],
        ];
    }

    /** Issue #50: the Vite dev server origins come from the marker file while it exists. */
    #[DataProvider('viteDevServerMarkers')]
    public function test_content_security_policy_allows_the_vite_dev_server_at_dev_time(string $marker, string $origin, string $socket): void
    {
        $this->writeHotMarker($marker);

        $expected = "default-src 'self'; script-src 'self' {$origin}; connect-src 'self' {$origin} {$socket}; style-src 'self' 'unsafe-inline' {$origin}; img-src 'self' data: {$origin}; font-src 'self' {$origin}; base-uri 'none'; frame-ancestors 'none'";

        $this->get('/')
            ->assertOk()
            ->assertHeader('Content-Security-Policy', $expected);
    }

    /** A stale or corrupted marker must never widen the policy. */
    public function test_a_marker_without_a_usable_origin_keeps_the_policy_strict(): void
    {
        foreach (['', 'not-a-url', '/@vite/client'] as $marker) {
            $this->writeHotMarker($marker);

            $this->get('/')->assertHeader('Content-Security-Policy', self::STRICT_CSP);
        }
    }

    /** Issue #50: the session cookie hides its value, stays same-site and is never Secure on plain http. */
    public function test_session_cookie_is_hardened_for_the_local_http_demo(): void
    {
        $cookies = $this->get('/login')->assertOk()->headers->all('Set-Cookie');
        $session = null;
        foreach ($cookies as $cookie) {
            if (str_starts_with($cookie, config('session.cookie').'=')) {
                $session = strtolower($cookie);
            }
        }

        $this->assertSame(Str::slug((string) config('app.name')).'-session', config('session.cookie'));
        $this->assertNotNull($session, 'The login page must start a session cookie.');
        $this->assertStringContainsString('; httponly', $session);
        // Lax on purpose: it keeps the session on a top-level visit from an external link
        // while still never sending the cookie on a cross-site POST (CSRF stays mitigated).
        $this->assertStringContainsString('; samesite=lax', $session);
        $this->assertStringNotContainsString('; secure', $session);
    }

    /** Issue #50: an early rejection from the default stack still carries the hardening headers. */
    public function test_a_maintenance_rejection_still_carries_the_security_headers(): void
    {
        $down = storage_path('framework/down');
        $existed = is_file($down);
        $backup = $existed ? (string) file_get_contents($down) : null;
        file_put_contents($down, '{}');

        try {
            $response = $this->get('/');
            $response->assertStatus(503);
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('X-Frame-Options', 'DENY');
            $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
            $this->assertSame(self::STRICT_CSP, $response->headers->get('Content-Security-Policy'));
        } finally {
            // Preserve whatever maintenance state the environment had before the test.
            if ($existed) {
                file_put_contents($down, $backup);
            } else {
                @unlink($down);
            }
        }
    }

    public function test_transport_security_is_sent_over_https(): void
    {
        foreach (['/', '/login'] as $path) {
            $this->get('https://localhost'.$path)
                ->assertOk()
                ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        }
    }

    /** The local demo runs on plain http, so it must never be pinned to https. */
    public function test_transport_security_is_absent_on_plain_http(): void
    {
        foreach (['/', '/login'] as $path) {
            $this->get($path)->assertOk()->assertHeaderMissing('Strict-Transport-Security');
        }
    }

    public function test_transport_security_is_absent_on_plain_http_redirects(): void
    {
        $this->get('/dashboard')
            ->assertRedirect('/login')
            ->assertHeaderMissing('Strict-Transport-Security');
    }
}
