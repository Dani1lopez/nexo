<?php

namespace App\Modules\Fees\Support;

/**
 * Euro amounts are stored as integer cents to avoid rounding errors.
 */
class Money
{
    /** Largest accepted input has six integer digits, i.e. 999999,99 euros. */
    private const PATTERN = '/^(\d{1,6})(?:[.,](\d{1,2}))?$/';

    public static function parse(?string $input): ?int
    {
        if (! preg_match(self::PATTERN, trim((string) $input), $matches)) {
            return null;
        }

        $cents = (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');

        return $cents > 0 ? $cents : null;
    }

    public static function format(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.').' €';
    }

    public static function input(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '');
    }
}
