<?php

namespace App\Services;

use App\Models\HotelSetting;

/**
 * Per-hotel key/value settings with defaults (database-schema.md → hotel_settings).
 * Always used inside the tenant context (the current hotel).
 */
class HotelSettings
{
    public const BOOKING_DEFAULTS = [
        'deposit_required' => true,
        'deposit_type' => 'percent', // fixed, percent, first_night
        'deposit_value' => '30',
        'pending_expiry_hours' => 24,
        'walk_in_payment' => 'full', // full, deposit
        'early_check_in_fee_type' => 'percent', // fixed, percent (of one night)
        'early_check_in_fee_value' => '50',
        'late_check_out_fee_type' => 'percent',
        'late_check_out_fee_value' => '50',
        'hourly_min_hours' => 2,
        'hourly_cleaning_minutes' => 30,
    ];

    public const INVOICE_DEFAULTS = [
        'invoice_prefix' => 'INV',
        'invoice_footer' => null,
    ];

    /** Sign-in security (decisions.md W7). screen_lock_minutes 0 = never lock. */
    public const SECURITY_DEFAULTS = [
        'require_two_factor_staff' => false,
        'approved_devices_only' => false,
        'screen_lock_minutes' => 15,
    ];

    public const ATTENDANCE_DEFAULTS = [
        'attendance_methods' => ['self', 'kiosk_pin', 'manual'],
    ];

    /** @return array<string, mixed> */
    public function group(array $defaults): array
    {
        $saved = HotelSetting::whereIn('key', array_keys($defaults))->pluck('value', 'key')->all();

        return array_merge($defaults, $saved);
    }

    /** @param  array<string, mixed>  $values */
    public function put(array $values): void
    {
        foreach ($values as $key => $value) {
            HotelSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return HotelSetting::where('key', $key)->value('value') ?? $default;
    }
}
