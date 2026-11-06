<?php

namespace App\Services;

use App\Models\CancellationPolicy;
use App\Models\Hotel;
use App\Models\HotelCurrency;
use App\Models\HotelModule;
use App\Models\HotelSetting;
use App\Models\PaymentMethod;
use App\Models\PlatformSetting;
use App\Models\Tax;
use App\Support\Tenancy\TenantContext;

/**
 * Sensible starting settings for a new hotel (the hotel can change all of
 * them in Settings or the setup walkthrough). Safe to run again: only fills
 * what is missing.
 */
class HotelDefaults
{
    public function __construct(
        private TenantContext $tenant,
        private HotelSettings $settings,
        private HotelModules $modules,
    ) {}

    public function apply(Hotel $hotel): void
    {
        $defaults = (array) (PlatformSetting::read('new_hotel_defaults') ?? []);
        $this->tenant->runAs($hotel, function () use ($hotel, $defaults) {
            if (! HotelCurrency::exists()) {
                HotelCurrency::create(['code' => $hotel->default_currency ?: 'ETB', 'symbol' => $hotel->default_currency === 'USD' ? '$' : 'Br', 'is_default' => true]);
            }

            if (! PaymentMethod::exists()) {
                foreach ([['Cash', 'cash'], ['telebirr', 'mobile_money'], ['CBE Birr', 'mobile_money'], ['Bank transfer', 'bank_transfer']] as [$name, $type]) {
                    PaymentMethod::create(['name' => $name, 'type' => $type, 'is_active' => true]);
                }
            }

            if (! Tax::exists()) {
                Tax::create(['name' => 'VAT', 'rate' => (string) ($defaults['vat_rate'] ?? '15.00'), 'applies_to' => 'all', 'is_inclusive' => false, 'is_active' => true]);
                Tax::create(['name' => 'Service charge', 'rate' => '10.00', 'applies_to' => 'all', 'is_inclusive' => false, 'is_active' => false]);
            }

            if (! CancellationPolicy::exists()) {
                $policies = [
                    ['Flexible', 24, 'nights', '1.00', false],
                    ['Standard', 48, 'nights', '1.00', true],
                    ['Strict', 168, 'percent', '50.00', false],
                    ['Non-refundable', null, 'percent', '100.00', false],
                ];
                foreach ($policies as [$name, $hours, $type, $value, $default]) {
                    CancellationPolicy::create([
                        'name' => $name, 'free_until_hours' => $hours, 'penalty_type' => $type,
                        'penalty_value' => $value, 'applies_to_no_show' => true, 'is_default' => $default,
                    ]);
                }
            }

            // Booking rules, invoice and attendance defaults (only missing keys).
            $current = array_merge(
                $this->settings->group(HotelSettings::BOOKING_DEFAULTS + HotelSettings::INVOICE_DEFAULTS + HotelSettings::ATTENDANCE_DEFAULTS),
                // Super Admin → Content: defaults for new hotels.
                array_filter(array_intersect_key($defaults, array_flip(['deposit_value', 'invoice_footer', 'receipt_paper'])), fn ($v) => $v !== null && $v !== ''),
            );
            $this->settings->put(array_diff_key($current, array_flip(HotelSetting::pluck('key')->all())));

            // Every module in the plan starts switched on.
            foreach ($this->modules->available($hotel) as $module) {
                HotelModule::firstOrCreate(['module' => $module], ['is_enabled' => true]);
            }
        });
    }
}
