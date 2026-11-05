<?php

namespace App\Support;

/**
 * Exact decimal maths for money (strings with 2 decimals). Never use floats.
 */
final class Money
{
    private const SCALE = 2;

    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::SCALE);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return self::round(bcmul($a, $b, self::SCALE + 2));
    }

    public static function max(string $a, string $b): string
    {
        return bccomp($a, $b, self::SCALE) >= 0 ? self::round($a) : self::round($b);
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    /** Rounds half away from zero to 2 decimals. */
    public static function round(string $value): string
    {
        $offset = str_starts_with($value, '-') ? '-0.005' : '0.005';

        return bcadd($value, $offset, self::SCALE);
    }
}
