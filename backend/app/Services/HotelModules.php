<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\HotelModule;
use App\Models\Plan;

/**
 * Optional modules (housekeeping, cashier shift close, shifts, attendance):
 * the plan decides which are available, the Hotel Admin turns them on/off
 * (decisions.md → Modules on/off, option C).
 */
class HotelModules
{
    /** @return list<string> modules the hotel's current plan includes */
    public function available(Hotel $hotel): array
    {
        return array_values($hotel->effectivePlan()?->modules ?? []);
    }

    /** @return list<string> modules switched on (and still in the plan) */
    public function enabled(Hotel $hotel): array
    {
        $on = HotelModule::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)
            ->where('is_enabled', true)->pluck('module')->all();

        return array_values(array_intersect($this->available($hotel), $on)); // plan order
    }

    public function isEnabled(Hotel $hotel, string $module): bool
    {
        return in_array($module, $this->enabled($hotel), true);
    }

    /** @return list<array{module: string, available: bool, enabled: bool, plans: list<string>}> */
    public function overview(Hotel $hotel): array
    {
        $available = $this->available($hotel);
        $enabled = $this->enabled($hotel);
        $plans = Plan::where('is_active', true)->orderBy('sort')->get();

        return array_map(fn (string $m) => [
            'module' => $m,
            'available' => in_array($m, $available, true),
            'enabled' => in_array($m, $enabled, true),
            'plans' => $plans->filter(fn (Plan $p) => in_array($m, $p->modules ?? [], true))->pluck('name')->values()->all(),
        ], Plan::MODULES);
    }
}
