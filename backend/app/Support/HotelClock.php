<?php

namespace App\Support;

use App\Models\Hotel;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Times are stored in UTC and shown in the hotel's own time zone
 * (Africa/Addis_Ababa by default). Input like "2026-11-12T14:00" is hotel time.
 */
class HotelClock
{
    public static function zone(?Hotel $hotel): string
    {
        return $hotel?->timezone ?: 'Africa/Addis_Ababa';
    }

    public static function parse(string $value, ?Hotel $hotel): Carbon
    {
        return Carbon::parse($value, self::zone($hotel))->utc();
    }

    public static function local(CarbonInterface $time, ?Hotel $hotel): Carbon
    {
        return Carbon::instance($time)->setTimezone(self::zone($hotel));
    }

    /** "2026-11-12T14:00:00+03:00" */
    public static function iso(?CarbonInterface $time, ?Hotel $hotel): ?string
    {
        return $time ? self::local($time, $hotel)->toIso8601String() : null;
    }

    public static function now(?Hotel $hotel): Carbon
    {
        return Carbon::now(self::zone($hotel));
    }
}
