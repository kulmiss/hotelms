<?php

namespace Tests\Unit;

use App\Support\EthiopianDate;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Ethiopian ⇄ Gregorian conversion, incl. Pagume and leap years. */
class EthiopianDateTest extends TestCase
{
    /** @return array<string, array{string, int, int, int}> Gregorian → EC, checked against published calendars. */
    public static function known(): array
    {
        return [
            'new year 2017 EC' => ['2024-09-11', 2017, 1, 1],
            'new year after a leap year' => ['2023-09-12', 2016, 1, 1],
            'Pagume 6 of leap year 2015' => ['2023-09-11', 2015, 13, 6],
            'Pagume 5 of 2016 (common)' => ['2024-09-10', 2016, 13, 5],
            'new year 2019 EC' => ['2026-09-11', 2019, 1, 1],
            'Pagume 6 of leap year 2019' => ['2027-09-11', 2019, 13, 6],
            'new year 2020 EC' => ['2027-09-12', 2020, 1, 1],
            'Genna 2024 (Tahsas 28)' => ['2024-01-07', 2016, 4, 28],
            'Genna 2025 (Tahsas 29)' => ['2025-01-07', 2017, 4, 29],
            'Timket 2025' => ['2025-01-19', 2017, 5, 11],
            'Adwa 2025' => ['2025-03-02', 2017, 6, 23],
            'Meskel 2026' => ['2026-09-27', 2019, 1, 17],
            'today' => ['2026-11-14', 2019, 3, 5],
            'Gregorian leap day' => ['2024-02-29', 2016, 6, 21],
            'year 2000' => ['2000-01-01', 1992, 4, 22],
        ];
    }

    #[DataProvider('known')]
    public function test_known_dates(string $greg, int $y, int $m, int $d): void
    {
        $this->assertSame([$y, $m, $d], EthiopianDate::fromGregorian($greg));
        $this->assertSame($greg, EthiopianDate::toGregorian($y, $m, $d));
    }

    public function test_every_day_from_1900_to_2100_goes_there_and_back(): void
    {
        $day = CarbonImmutable::parse('1900-01-01');
        $prev = null;
        while ($day->year <= 2100) {
            $ec = EthiopianDate::fromGregorian($day);
            $this->assertSame($day->toDateString(), EthiopianDate::toGregorian(...$ec));
            if ($prev) { // the next day in EC is always one step on
                [$py, $pm, $pd] = $prev;
                $expected = $pd < EthiopianDate::daysInMonth($py, $pm) ? [$py, $pm, $pd + 1] : ($pm < 13 ? [$py, $pm + 1, 1] : [$py + 1, 1, 1]);
                $this->assertSame($expected, $ec, $day->toDateString());
            }
            $prev = $ec;
            $day = $day->addDay();
        }
    }

    public function test_leap_years_and_pagume(): void
    {
        $this->assertTrue(EthiopianDate::isLeap(2015));
        $this->assertFalse(EthiopianDate::isLeap(2016));
        $this->assertSame(6, EthiopianDate::daysInMonth(2019, 13));
        $this->assertSame(5, EthiopianDate::daysInMonth(2018, 13));
        $this->assertSame(30, EthiopianDate::daysInMonth(2018, 4));
        $this->expectException(InvalidArgumentException::class);
        EthiopianDate::toGregorian(2018, 13, 6); // 2018 is not a leap year
    }

    public function test_format(): void
    {
        $this->assertSame('5 Hidar 2019', EthiopianDate::format('2026-11-14'));
        $this->assertSame('5 ኅዳር 2019', EthiopianDate::format('2026-11-14', 'am'));
    }
}
