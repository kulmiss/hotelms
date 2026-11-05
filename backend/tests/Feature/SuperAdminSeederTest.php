<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_owner_account_once(): void
    {
        $this->seed(SuperAdminSeeder::class);
        $this->seed(SuperAdminSeeder::class);

        $admins = User::where('email', 'owner@hotelms.test')->get();

        $this->assertCount(1, $admins);
        $this->assertTrue($admins->first()->is_super_admin);
        $this->assertNull($admins->first()->hotel_id);
        $this->assertTrue($admins->first()->must_change_password);
    }
}
