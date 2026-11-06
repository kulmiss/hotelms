<?php

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\Hotel;
use App\Models\Plan;
use App\Models\RoomType;
use App\Models\User;
use App\Services\HotelDefaults;
use App\Services\Switches;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Super Admin switches (decisions.md W4): everyone → plan → hotel, gifts, schedules, emergency, banners. */
class SwitchesTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private Hotel $other;

    private Plan $basic;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basic = Plan::factory()->create(['name' => 'Basic', 'modules' => ['cashier_close'], 'max_rooms' => 10]);
        $this->hotel = Hotel::factory()->create(['status' => 'active', 'plan_id' => $this->basic->id]);
        $this->other = Hotel::factory()->create(['status' => 'active', 'plan_id' => $this->basic->id]);
        foreach ([$this->hotel, $this->other] as $h) {
            app(HotelDefaults::class)->apply($h);
            Branch::factory()->for($h)->create();
        }
        $this->owner = User::factory()->superAdmin()->create();
        $this->admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();
    }

    private function set(array $data): TestResponse
    {
        return $this->actingAs($this->owner)->postJson('/api/v1/admin/switches', $data + ['starts_at' => null, 'ends_at' => null]);
    }

    private function feature(string $name, ?User $as = null): mixed
    {
        return $this->actingAs($as ?? $this->admin)->getJson('/api/v1/me')->json("switches.$name");
    }

    public function test_the_most_specific_level_wins(): void
    {
        $this->assertTrue($this->feature('hourly_stays'));
        $this->set(['key' => 'feature.hourly_stays', 'level' => 'everyone', 'value' => false])->assertCreated();
        $this->assertFalse($this->feature('hourly_stays'));
        $this->set(['key' => 'feature.hourly_stays', 'level' => 'plan', 'target_id' => $this->basic->id, 'value' => true])->assertCreated();
        $this->assertTrue($this->feature('hourly_stays'));
        $this->set(['key' => 'feature.hourly_stays', 'level' => 'hotel', 'target_id' => $this->hotel->id, 'value' => false])->assertCreated();
        $this->assertFalse($this->feature('hourly_stays'));
        // The other hotel on the same plan follows the plan.
        $otherAdmin = User::factory()->withRole($this->other, 'hotel_admin')->create();
        $this->assertTrue($this->feature('hourly_stays', $otherAdmin));

        $this->actingAs($this->owner)->getJson("/api/v1/admin/hotels/{$this->hotel->id}/switches")->assertOk()
            ->assertJsonFragment(['key' => 'feature.hourly_stays', 'value' => false, 'source' => 'hotel']);
    }

    public function test_switched_off_stays_are_refused(): void
    {
        $this->set(['key' => 'feature.hourly_stays', 'level' => 'hotel', 'target_id' => $this->hotel->id, 'value' => false]);
        $branch = Branch::where('hotel_id', $this->hotel->id)->first();
        $type = app(TenantContext::class)->runAs($this->hotel, fn () => RoomType::create(['branch_id' => $branch->id, 'name' => 'Deluxe', 'base_capacity' => 2, 'max_capacity' => 2, 'nightly_price' => '3000', 'hourly_price' => '300', 'currency' => 'ETB']));
        $this->actingAs($this->admin)->getJson('/api/v1/reservations/lookups')->assertJsonPath('stay_types', ['nightly', 'day_use']);
        $this->postJson('/api/v1/reservations/quote', ['branch_id' => $branch->id, 'stay_type' => 'hourly', 'check_in' => now()->addDay()->format('Y-m-d').'T10:00', 'hours' => 3, 'rooms' => [['room_type_id' => $type->id]]])
            ->assertJsonValidationErrors('stay_type');
    }

    public function test_a_gift_with_an_end_date_and_a_scheduled_change(): void
    {
        // Housekeeping is not in Basic: gift it to one hotel until next week.
        $this->set(['key' => 'module.housekeeping', 'level' => 'hotel', 'target_id' => $this->hotel->id, 'value' => true, 'ends_at' => now()->addWeek()->toIso8601String(), 'reason' => 'Free trial of housekeeping'])->assertCreated();
        $this->actingAs($this->admin)->putJson('/api/v1/settings/modules', ['modules' => ['housekeeping' => true]])->assertOk();
        $this->assertContains('housekeeping', $this->getJson('/api/v1/me')->json('hotel.modules'));

        $this->travel(8)->days();
        app(Switches::class)->flush();
        $this->assertNotContains('housekeeping', $this->getJson('/api/v1/me')->json('hotel.modules')); // the gift ended

        // Scheduled: the room limit goes up from tomorrow.
        $this->set(['key' => 'limit.rooms', 'level' => 'hotel', 'target_id' => $this->hotel->id, 'value' => 50, 'starts_at' => now()->addDay()->toIso8601String()])->assertCreated();
        $this->assertSame(10, $this->hotel->fresh()->limit('rooms'));
        $this->travel(2)->days();
        app(Switches::class)->flush();
        $this->assertSame(50, $this->hotel->fresh()->limit('rooms'));
    }

    public function test_emergency_maintenance_and_read_only(): void
    {
        $this->set(['key' => 'emergency.read_only', 'level' => 'hotel', 'target_id' => $this->hotel->id, 'value' => true]);
        $this->actingAs($this->admin)->getJson('/api/v1/branches')->assertOk();
        $this->postJson('/api/v1/guests', ['first_name' => 'Abebe'])->assertStatus(423)->assertJsonPath('code', 'read_only');

        $this->set(['key' => 'emergency.maintenance', 'level' => 'everyone', 'value' => true]);
        $this->actingAs($this->admin)->getJson('/api/v1/branches')->assertStatus(503)->assertJsonPath('code', 'maintenance');
        $this->getJson('/api/v1/me')->assertOk(); // the app can still show the message
        $this->actingAs($this->owner)->getJson('/api/v1/admin/dashboard')->assertOk(); // HotelMS is never locked out
    }

    public function test_banners_reach_the_chosen_hotels_only(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/admin/banners', ['message' => 'Update tonight at 02:00', 'tone' => 'info', 'hotel_ids' => [$this->hotel->id]])->assertCreated();
        $this->actingAs($this->admin)->getJson('/api/v1/me')->assertJsonPath('banners.0.message', 'Update tonight at 02:00');
        $otherAdmin = User::factory()->withRole($this->other, 'hotel_admin')->create();
        $this->actingAs($otherAdmin)->getJson('/api/v1/me')->assertJsonCount(0, 'banners');
    }

    public function test_only_owner_and_tech_change_switches(): void
    {
        $sales = User::factory()->platformRole('sales')->create();
        $this->actingAs($sales)->getJson('/api/v1/admin/switches')->assertForbidden();
        $tech = User::factory()->platformRole('tech')->create();
        $this->actingAs($tech)->getJson('/api/v1/admin/switches')->assertOk();
        $this->actingAs($this->admin)->getJson('/api/v1/admin/switches')->assertForbidden();
        $this->set(['key' => 'feature.nope', 'level' => 'everyone', 'value' => true])->assertJsonValidationErrors('key');
        $this->set(['key' => 'limit.rooms', 'level' => 'everyone', 'value' => true])->assertJsonValidationErrors('value');
    }
}
