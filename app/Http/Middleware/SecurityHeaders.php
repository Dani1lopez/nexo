<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser hardening headers for every response (issue #50).
 *
 * The policy is strict by default. While Vite is running it leaves a marker file
 * (public/hot) with its dev-server origin; only then are the dev-server origins
 * added, so the local demo keeps hot reload and production stays strict.
 */
final class SecurityHeaders
{
    private const STRICT_CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; base-uri 'none'; frame-ancestors 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());

        // Only meaningful over HTTPS, and it must never be learned from a plain http demo.
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $devServer = $this->viteDevServerOrigin();

        if ($devServer === null) {
            return self::STRICT_CSP;
        }

        // The dev server serves the stylesheet, its fonts and the client module itself.
        $socket = $this->websocketOrigin($devServer);
        $directives = [
            'default-src' => "'self'",
            'script-src' => "'self' {$devServer}",
            'connect-src' => "'self' {$devServer} {$socket}",
            'style-src' => "'self' 'unsafe-inline' {$devServer}",
            'img-src' => "'self' data: {$devServer}",
            'font-src' => "'self' {$devServer}",
            'base-uri' => "'none'",
            'frame-ancestors' => "'none'",
        ];

        $policy = [];
        foreach ($directives as $directive => $sources) {
            $policy[] = $directive.' '.$sources;
        }

        return implode('; ', $policy);
    }

    /**
     * Origin of the running Vite dev server, read from its marker file, or null.
     * Empty, stale or malformed markers must never widen the policy.
     */
    private function viteDevServerOrigin(): ?string
    {
        $marker = public_path('hot');

        if (! is_file($marker)) {
            return null;
        }

        $content = trim((string) file_get_contents($marker));
        $parts = parse_url($content);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (! in_array($parts['scheme'], ['http', 'https'], true)) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }

    private function websocketOrigin(string $origin): string
    {
        return (string) preg_replace('/^http/', 'ws', $origin, 1);
    }
}
