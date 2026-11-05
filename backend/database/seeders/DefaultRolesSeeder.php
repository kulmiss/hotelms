<?php

namespace Database\Seeders;

use App\Models\Hotel;
use App\Models\User;
use App\Services\DefaultRoles;
use App\Services\HotelDefaults;
use Illuminate\Database\Seeder;

/**
 * Hotels created before roles existed: add the default roles and make each
 * hotel's first user its Hotel Admin. Safe to run on every start.
 */
class DefaultRolesSeeder extends Seeder
{
    public function run(DefaultRoles $defaults, HotelDefaults $settings): void
    {
        Hotel::query()->each(function (Hotel $hotel) use ($defaults, $settings) {
            $roles = $defaults->ensureFor($hotel);
            $settings->apply($hotel);

            $hasAdmin = User::where('hotel_id', $hotel->id)->where('role_id', $roles['hotel_admin']->id)->exists();
            if (! $hasAdmin) {
                User::where('hotel_id', $hotel->id)->whereNull('role_id')->orderBy('id')->first()
                    ?->forceFill(['role_id' => $roles['hotel_admin']->id])->save();
            }
        });
    }
}
