<?php

namespace App\Support;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * Ethiopian calendar (Amete Mihret) ⇄ Gregorian, through the Julian Day Number (owner, 14 Nov 2026).
 * 12 months of 30 days + Pagume (5 days, 6 in a leap year: EC year % 4 == 3). The database keeps Gregorian;
 * EC is only for showing and typing dates. Same maths as frontend/src/lib/ethiopian.ts.
 */
class EthiopianDate
{
    /** JDN of Meskerem 1, year 1 (Amete Mihret). */
    public const EPOCH = 1724221;

    public const MONTHS = ['Meskerem', 'Tikimt', 'Hidar', 'Tahsas', 'Tir', 'Yekatit', 'Megabit', 'Miyazia', 'Ginbot', 'Sene', 'Hamle', 'Nehase', 'Pagume'];

    public const MONTHS_AM = ['መስከረም', 'ጥቅምት', 'ኅዳር', 'ታኅሣሥ', 'ጥር', 'የካቲት', 'መጋቢት', 'ሚያዝያ', 'ግንቦት', 'ሰኔ', 'ሐምሌ', 'ነሐሴ', 'ጳጉሜ'];

    public static function isLeap(int $year): bool
    {
        return $year % 4 === 3;
    }

    public static function daysInMonth(int $year, int $month): int
    {
        return $month < 13 ? 30 : (self::isLeap($year) ? 6 : 5);
    }

    /** @return array{0: int, 1: int, 2: int} [year, month, day] */
    public static function fromGregorian(DateTimeInterface|string $date): array
    {
        [$y, $m, $d] = array_map('intval', explode('-', $date instanceof DateTimeInterface ? $date->format('Y-m-d') : substr($date, 0, 10)));

        return self::fromJdn(self::gregorianToJdn($y, $m, $d));
    }

    /** "Y-m-d" (Gregorian) for an Ethiopian date; refuses dates that don't exist (e.g. Pagume 6 in a common year). */
    public static function toGregorian(int $year, int $month, int $day): string
    {
        if ($year < 1 || $month < 1 || $month > 13 || $day < 1 || $day > self::daysInMonth($year, $month)) {
            throw new InvalidArgumentException("Not an Ethiopian date: {$day}/{$month}/{$year}");
        }
        [$gy, $gm, $gd] = self::jdnToGregorian(self::toJdn($year, $month, $day));

        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }

    /** "5 Hidar 2019" (or in Amharic: "5 ኅዳር 2019"). */
    public static function format(DateTimeInterface|string $date, string $language = 'en'): string
    {
        [$y, $m, $d] = self::fromGregorian($date);

        return "{$d} ".($language === 'am' ? self::MONTHS_AM : self::MONTHS)[$m - 1]." {$y}";
    }

    public static function toJdn(int $year, int $month, int $day): int
    {
        return self::EPOCH + 365 * ($year - 1) + intdiv($year, 4) + 30 * ($month - 1) + $day - 1;
    }

    /** @return array{0: int, 1: int, 2: int} */
    public static function fromJdn(int $jdn): array
    {
        $year = intdiv(4 * ($jdn - self::EPOCH) + 1463, 1461);
        $month = intdiv($jdn - self::toJdn($year, 1, 1), 30) + 1;
        $day = $jdn - self::toJdn($year, $month, 1) + 1;

        return [$year, $month, $day];
    }

    public static function gregorianToJdn(int $y, int $m, int $d): int
    {
        $a = intdiv(14 - $m, 12);
        $y2 = $y + 4800 - $a;
        $m2 = $m + 12 * $a - 3;

        return $d + intdiv(153 * $m2 + 2, 5) + 365 * $y2 + intdiv($y2, 4) - intdiv($y2, 100) + intdiv($y2, 400) - 32045;
    }

    /** @return array{0: int, 1: int, 2: int} */
    public static function jdnToGregorian(int $jdn): array
    {
        $a = $jdn + 32044;
        $b = intdiv(4 * $a + 3, 146097);
        $c = $a - intdiv(146097 * $b, 4);
        $d = intdiv(4 * $c + 3, 1461);
        $e = $c - intdiv(1461 * $d, 4);
        $m = intdiv(5 * $e + 2, 153);

        return [100 * $b + $d - 4800 + intdiv($m, 10), $m + 3 - 12 * intdiv($m, 10), $e - intdiv(153 * $m + 2, 5) + 1];
    }
}
