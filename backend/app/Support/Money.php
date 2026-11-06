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

    public static function min(string $a, string $b): string
    {
        return bccomp($a, $b, self::SCALE) <= 0 ? self::round($a) : self::round($b);
    }

    /** $percent % of $amount, e.g. percentOf('2500.00', '30') = '750.00'. */
    public static function percentOf(string $amount, string $percent): string
    {
        return self::round(bcdiv(bcmul($amount, $percent, 6), '100', 6));
    }

    /** @param  iterable<string>  $amounts */
    public static function sum(iterable $amounts): string
    {
        $total = '0.00';
        foreach ($amounts as $a) {
            $total = bcadd($total, (string) $a, self::SCALE);
        }

        return $total;
    }

    public static function isPositive(string $a): bool
    {
        return bccomp($a, '0', self::SCALE) > 0;
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
