<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates the platform owner account once. Safe to run again.
 * Email and first password come from SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('hotelms.super_admin.email');

        if (User::withTrashed()->where('email', $email)->exists()) {
            return;
        }

        $password = (string) config('hotelms.super_admin.password');
        if ($password === '' && app()->environment('local')) {
            $password = 'ChangeMe-123'; // easy first sign-in on a developer laptop
        }
        $generated = $password === '';
        if ($generated) {
            $password = Str::password(16, symbols: false);
        }

        $user = new User([
            'name' => 'Super Admin',
            'email' => $email,
            'password' => $password,
            'status' => 'active',
            'must_change_password' => true,
        ]);
        $user->is_super_admin = true;
        $user->save();

        $this->command?->info("Super Admin created: {$email}".($generated ? " / temporary password: {$password}" : ''));
    }
}
