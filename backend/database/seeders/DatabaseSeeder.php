<?php

namespace Database\Seeders;

use App\Services\DemoHotel;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Safe to run on every start: nothing is overwritten. */
    public function run(): void
    {
        $this->call([PlatformSeeder::class, SuperAdminSeeder::class, DefaultRolesSeeder::class]);

        // A demo hotel to explore (rebuilt every night).
        if (! app(DemoHotel::class)->current()) {
            app(DemoHotel::class)->reset();
        }
    }
}
