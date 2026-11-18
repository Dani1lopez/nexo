<?php

namespace App\Modules\Access\Support;

use InvalidArgumentException;

/**
 * RFC 6238 time-based one-time passwords, HMAC-SHA1, without an external package.
 * Secrets travel as RFC 4648 Base32 without padding, which is what the
 * otpauth:// provisioning URI expects.
 */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const PERIOD = 30;

    /**
     * A 160-bit secret, the length RFC 4226 recommends for HMAC-SHA1.
     */
    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function code(string $secret, int $timestamp, int $digits = 6): string
    {
        if ($digits < 6 || $digits > 8) {
            throw new InvalidArgumentException('El número de dígitos del código no es válido.');
        }

        $counter = intdiv($timestamp, self::PERIOD);
        $hash = hash_hmac('sha1', pack('J', $counter), $this->base32Decode($secret), true);

        // RFC 4226 dynamic truncation: the low nibble of the last byte picks the window.
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Accept only the current step and a symmetric window around it, so a small
     * clock difference does not lock a legitimate user out.
     */
    public function verify(string $secret, string $code, ?int $now = null, int $window = 1): bool
    {
        $code = trim($code);
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $now ??= time();
        $window = max(0, $window);

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->code($secret, $now + $offset * self::PERIOD), $code)) {
                return true;
            }
        }

        return false;
    }

    public function otpauthUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);

        return "otpauth://totp/{$label}?{$query}";
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $encoded;
    }

    private function base32Decode(string $secret): string
    {
        $normalized = strtoupper((string) preg_replace('/[\s=]+/', '', $secret));
        if ($normalized === '' || preg_match('/[^A-Z2-7]/', $normalized) === 1) {
            throw new InvalidArgumentException('El secreto de verificación no es válido.');
        }

        $bits = '';
        foreach (str_split($normalized) as $character) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $character)), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $decoded .= chr(bindec($byte));
            }
        }

        return $decoded;
    }
}
