<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\PlatformSetting;
use Illuminate\Database\Seeder;

/**
 * Default plans and platform settings (claude/plans.md). Prices are
 * placeholders (0) for the owner to set. Never overwrites edits.
 */
class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['code' => 'basic', 'name' => 'Basic', 'description' => 'Small hotels and guest houses', 'max_rooms' => 20, 'max_branches' => 1, 'max_staff' => 5, 'modules' => [], 'sort' => 1],
            ['code' => 'standard', 'name' => 'Standard', 'description' => 'Mid-size hotels', 'max_rooms' => 60, 'max_branches' => 2, 'max_staff' => 15, 'modules' => ['housekeeping', 'cashier_close'], 'sort' => 2],
            ['code' => 'pro', 'name' => 'Pro', 'description' => 'Big hotels and chains', 'max_rooms' => null, 'max_branches' => null, 'max_staff' => null, 'modules' => Plan::MODULES, 'sort' => 3],
            ['code' => 'custom', 'name' => 'Custom', 'description' => 'Large chains – price by agreement', 'max_rooms' => null, 'max_branches' => null, 'max_staff' => null, 'modules' => Plan::MODULES, 'sort' => 4, 'is_custom' => true],
        ];

        foreach ($plans as $plan) {
            Plan::firstOrCreate(['code' => $plan['code']], [
                ...$plan,
                'price_per_room' => '0.00',
                'minimum_monthly' => '0.00',
                'yearly_free_months' => 2,
                'currency' => 'ETB',
                'is_active' => true,
            ]);
        }

        if (! PlatformSetting::where('key', 'default_trial_plan_id')->exists()) {
            PlatformSetting::write('default_trial_plan_id', Plan::where('code', 'pro')->value('id'));
        }
    }
}
