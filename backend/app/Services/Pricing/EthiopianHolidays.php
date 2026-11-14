<?php

namespace App\Services\Pricing;

use App\Support\EthiopianDate;
use Illuminate\Support\Carbon;

/**
 * The Ethiopian public holidays (owner, 14 Nov 2026): a preset the hotel adds to its holiday list and can edit.
 * - fixed: Genna and Timket on their usual Gregorian days, the others from their Ethiopian date;
 * - calculated: Fasika (Orthodox Easter, Julian computus) and Siklet two days before;
 * - expected: Eid al-Fitr, Eid al-Adha and Mawlid follow the moon – these dates are the usual forecasts
 *   and must be checked against the official announcement (they can move by a day).
 */
class EthiopianHolidays
{
    /** Forecast dates of the moon holidays (timeanddate.com / holidaydb, checked 14 Nov 2026). */
    public const MOON = [
        2026 => ['eid_fitr' => '2026-03-20', 'eid_adha' => '2026-05-27', 'mawlid' => '2026-08-26'],
        2027 => ['eid_fitr' => '2027-03-10', 'eid_adha' => '2027-05-17', 'mawlid' => '2027-08-15'],
        2028 => ['eid_fitr' => '2028-02-26', 'eid_adha' => '2028-05-05', 'mawlid' => '2028-08-03'],
        2029 => ['eid_fitr' => '2029-02-15', 'eid_adha' => '2029-04-24', 'mawlid' => '2029-07-24'],
    ];

    public const NAMES = [
        'genna' => ['en' => 'Genna (Ethiopian Christmas)', 'am' => 'ገና'],
        'timket' => ['en' => 'Timket (Epiphany)', 'am' => 'ጥምቀት'],
        'adwa' => ['en' => 'Adwa Victory Day', 'am' => 'የዓድዋ ድል በዓል'],
        'labour' => ['en' => 'International Labour Day', 'am' => 'የሠራተኞች ቀን'],
        'patriots' => ['en' => "Patriots' Victory Day", 'am' => 'የአርበኞች ቀን'],
        'derg' => ['en' => 'Derg Downfall Day', 'am' => 'ግንቦት 20'],
        'enkutatash' => ['en' => 'Enkutatash (New Year)', 'am' => 'እንቁጣጣሽ (አዲስ ዓመት)'],
        'meskel' => ['en' => 'Meskel', 'am' => 'መስቀል'],
        'siklet' => ['en' => 'Siklet (Good Friday)', 'am' => 'ስቅለት'],
        'fasika' => ['en' => 'Fasika (Easter)', 'am' => 'ፋሲካ'],
        'eid_fitr' => ['en' => 'Eid al-Fitr', 'am' => 'ዒድ አል-ፈጥር'],
        'eid_adha' => ['en' => 'Eid al-Adha (Arefa)', 'am' => 'ዒድ አል-አድሐ (አረፋ)'],
        'mawlid' => ['en' => 'Mawlid', 'am' => 'መውሊድ'],
    ];

    /** Orthodox Easter (Fasika) of a Gregorian year: Meeus' Julian algorithm + 13 days (valid 1900–2099). */
    public static function fasika(int $year): Carbon
    {
        $a = $year % 4;
        $b = $year % 7;
        $c = $year % 19;
        $d = (19 * $c + 15) % 30;
        $e = (2 * $a + 4 * $b - $d + 34) % 7;
        $month = intdiv($d + $e + 114, 31);
        $day = (($d + $e + 114) % 31) + 1;

        return Carbon::create($year, $month, $day)->addDays(13)->startOfDay();
    }

    /** The holidays of one Gregorian year. @return list<array{key: string, date: string, kind: string}> */
    public static function year(int $year): array
    {
        $ec = fn (int $m, int $d) => self::fromEthiopian($year, $m, $d);
        $fasika = self::fasika($year);
        $list = [
            ['key' => 'genna', 'date' => sprintf('%d-01-07', $year), 'kind' => 'fixed'],
            ['key' => 'timket', 'date' => sprintf('%d-01-19', $year), 'kind' => 'fixed'],
            ['key' => 'adwa', 'date' => $ec(6, 23), 'kind' => 'fixed'], // Yekatit 23
            ['key' => 'siklet', 'date' => $fasika->copy()->subDays(2)->toDateString(), 'kind' => 'calculated'],
            ['key' => 'fasika', 'date' => $fasika->toDateString(), 'kind' => 'calculated'],
            ['key' => 'labour', 'date' => sprintf('%d-05-01', $year), 'kind' => 'fixed'],
            ['key' => 'patriots', 'date' => $ec(8, 27), 'kind' => 'fixed'], // Miyazya 27
            ['key' => 'derg', 'date' => $ec(9, 20), 'kind' => 'fixed'], // Ginbot 20
            ['key' => 'enkutatash', 'date' => $ec(1, 1), 'kind' => 'fixed'], // Meskerem 1
            ['key' => 'meskel', 'date' => $ec(1, 17), 'kind' => 'fixed'], // Meskerem 17
        ];
        foreach (self::MOON[$year] ?? [] as $key => $date) {
            $list[] = ['key' => $key, 'date' => $date, 'kind' => 'expected'];
        }
        usort($list, fn ($x, $y) => strcmp($x['date'], $y['date']));

        return $list;
    }

    /** From today until the same day 3 years later, named in the hotel's language. @return list<array{name: string, date: string, kind: string}> */
    public static function upcoming(string $lang = 'en', ?Carbon $today = null, int $years = 3): array
    {
        $from = ($today ?? now())->copy()->startOfDay();
        $until = $from->copy()->addYears($years);
        $out = [];
        for ($y = $from->year; $y <= $until->year; $y++) {
            foreach (self::year($y) as $h) {
                if ($h['date'] >= $from->toDateString() && $h['date'] <= $until->toDateString()) {
                    $out[] = ['name' => self::NAMES[$h['key']][$lang === 'am' ? 'am' : 'en'], 'date' => $h['date'], 'kind' => $h['kind']];
                }
            }
        }

        return $out;
    }

    /** The Gregorian date, in Gregorian year $year, of an Ethiopian month/day (Meskerem dates fall in September). */
    private static function fromEthiopian(int $gregorianYear, int $month, int $day): string
    {
        // Meskerem–Tahsas (months 1–4, roughly Sep–Dec) are in EC year = Gregorian year − 7; later months in − 8.
        $ecYear = $month <= 4 ? $gregorianYear - 7 : $gregorianYear - 8;

        return EthiopianDate::toGregorian($ecYear, $month, $day);
    }
}
