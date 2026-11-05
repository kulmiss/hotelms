<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Safe to run on every start: nothing is overwritten. */
    public function run(): void
    {
        $this->call([PlatformSeeder::class, SuperAdminSeeder::class, DefaultRolesSeeder::class]);
    }
}
