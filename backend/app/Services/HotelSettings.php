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
        'allow_pay_later' => false, // staff with the permission may let a guest pay everything at check-out
        'id_photo_enabled' => false, // staff may take a photo of the guest's ID or passport
        'id_photo_required' => false, // …and it is needed before check-in
        'id_photo_keep_months' => 12, // removed this long after the guest's last stay (0 = keep)
        'early_check_in_fee_type' => 'percent', // fixed, percent (of one night)
        'early_check_in_fee_value' => '50',
        'late_check_out_fee_type' => 'percent',
        'late_check_out_fee_value' => '50',
        // A guest who leaves before the booked date pays the nights stayed plus this fee (owner, 2026-11-07).
        'early_check_out_fee_type' => 'percent', // fixed, percent (of one night)
        'early_check_out_fee_value' => '50',
        // A walk-in arriving before this time belongs to the night before (00:14 on the 7th = the night of the 6th).
        'night_cutoff' => '06:00',
        'hourly_min_hours' => 2,
        'hourly_cleaning_minutes' => 30,
    ];

    public const INVOICE_DEFAULTS = [
        'invoice_prefix' => 'INV',
        'invoice_footer' => null,
        'receipt_paper' => 'a4', // a4 or receipt (80mm) – the default when printing (decisions.md W9)
        'calendar' => 'gregorian', // gregorian, ethiopian or both – staff can choose their own (owner, 14 Nov 2026)
        'language' => 'en', // en or am: the default for staff and guest messages
    ];

    public const LANGUAGES = ['en', 'am'];

    public const CALENDARS = ['gregorian', 'ethiopian', 'both'];

    /** Sign-in security (decisions.md W7). screen_lock_minutes 0 = never lock. */
    public const SECURITY_DEFAULTS = [
        'require_two_factor_staff' => false,
        'approved_devices_only' => false,
        'screen_lock_minutes' => 15,
    ];

    /** Quiet hours (automation.md): no Telegram to staff in this time, except urgent messages. In-app always. */
    public const QUIET_DEFAULTS = [
        'quiet_hours' => true,
        'quiet_from' => '23:00',
        'quiet_to' => '06:00',
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
