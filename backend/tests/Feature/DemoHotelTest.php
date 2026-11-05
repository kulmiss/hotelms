<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\PlatformSetting;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Services\DemoHotel;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoHotelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlatformSeeder::class);
    }

    public function test_demo_has_two_branches_and_one_person_per_role(): void
    {
        $hotel = app(DemoHotel::class)->reset();

        $this->assertTrue($hotel->is_demo);
        $this->assertSame('active', $hotel->status);
        $this->assertSame(2, app(TenantContext::class)->runAs($hotel, fn () => Branch::count()));
        foreach (array_keys(DemoHotel::PEOPLE) as $role) {
            $user = User::where('email', DemoHotel::email($role))->firstOrFail();
            $this->assertSame($role, $user->role->key);
            $this->assertFalse($user->must_change_password);
        }
    }

    public function test_demo_has_history_guests_in_house_and_bills(): void
    {
        $hotel = app(DemoHotel::class)->reset();
        app(TenantContext::class)->runAs($hotel, function () {
            $this->assertSame(60, Room::count());
            $this->assertGreaterThan(50, Reservation::where('status', 'checked_out')->count());
            $this->assertGreaterThan(0, Reservation::where('status', 'checked_in')->count());
            $this->assertGreaterThan(10, Reservation::whereIn('status', ['pending', 'confirmed'])->count());
            $this->assertGreaterThan(50, Invoice::where('status', 'paid')->count());
            $this->assertTrue(Reservation::where('status', 'checked_out')->where('check_in_at', '<', now()->subMonths(3))->exists());
        });
    }

    public function test_reset_wipes_visitors_changes(): void
    {
        $demo = app(DemoHotel::class);
        $hotel = $demo->reset();
        $hotel->update(['name' => 'Changed by a visitor']);

        $fresh = $demo->reset();

        $this->assertSame('Sheger Demo Hotel', $fresh->name);
        $this->assertSame(1, Hotel::where('is_demo', true)->count());
    }

    public function test_try_the_demo_only_works_when_switched_on(): void
    {
        app(DemoHotel::class)->reset();

        $this->postJson('/api/v1/demo/login', ['role' => 'receptionist'])->assertNotFound();

        PlatformSetting::write('demo_public', true);
        $this->postJson('/api/v1/demo/login', ['role' => 'receptionist'])->assertOk();
        $this->getJson('/api/v1/me')->assertJsonPath('user.email', DemoHotel::email('receptionist'));
    }

    public function test_the_nightly_command_rebuilds_the_demo(): void
    {
        $this->artisan('demo:reset')->assertSuccessful();

        $this->assertSame(1, Hotel::where('is_demo', true)->count());
    }
}
