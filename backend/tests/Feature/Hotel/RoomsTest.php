<?php

namespace Tests\Feature\Hotel;

use App\Models\Branch;
use App\Models\Hotel;
use App\Models\Plan;
use App\Models\Room;
use App\Models\User;
use App\Services\HotelDefaults;
use App\Services\Subscriptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomsTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private User $admin;

    private Branch $main;

    private Branch $second;

    protected function setUp(): void
    {
        parent::setUp();
        $plan = Plan::factory()->create(['max_rooms' => 20, 'max_branches' => 2]);
        $this->hotel = Hotel::factory()->create(['plan_id' => $plan->id]);
        $this->main = Branch::factory()->for($this->hotel)->create(['name' => 'Main']);
        $this->second = Branch::factory()->for($this->hotel)->create(['name' => 'Second']);
        app(HotelDefaults::class)->apply($this->hotel);
        $this->admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();
    }

    private function type(array $extra = []): int
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/settings/room-types', $extra + [
            'branch_id' => $this->main->id, 'name' => 'Standard', 'base_capacity' => 2, 'max_capacity' => 3,
            'nightly_price' => '2500', 'currency' => 'ETB', 'amenities' => ['Wi-Fi'],
        ])->assertCreated()->json('data.id');
    }

    public function test_room_type_crud_and_unique_name_per_branch(): void
    {
        $id = $this->type();
        $this->postJson('/api/v1/settings/room-types', ['branch_id' => $this->main->id, 'name' => 'Standard', 'base_capacity' => 2, 'max_capacity' => 2, 'nightly_price' => '1', 'currency' => 'ETB'])
            ->assertJsonValidationErrors('name');
        $this->type(['branch_id' => $this->second->id]); // same name in another branch is fine

        $this->putJson("/api/v1/settings/room-types/$id", ['branch_id' => $this->main->id, 'name' => 'Standard', 'base_capacity' => 2, 'max_capacity' => 1, 'nightly_price' => '2600', 'currency' => 'ETB'])
            ->assertJsonValidationErrors('max_capacity');
        $this->getJson('/api/v1/settings/room-types?branch_id='.$this->main->id)->assertJsonCount(1, 'data')->assertJsonPath('data.0.nightly_price', '2500.00');
    }

    public function test_generator_previews_then_creates_rooms_by_floor(): void
    {
        $standard = $this->type();
        $suite = $this->type(['name' => 'Suite', 'nightly_price' => '6000']);
        $body = ['room_type_id' => $standard, 'first_floor' => 1, 'floors' => 2, 'rooms_per_floor' => 5, 'start_number' => 1, 'types' => ['5' => $suite]];

        $preview = $this->postJson('/api/v1/settings/rooms/generate', $body + ['preview' => true])->assertOk();
        $preview->assertJsonCount(10, 'data')->assertJsonPath('data.0.number', '101')->assertJsonPath('data.4.room_type', 'Suite')->assertJsonPath('data.9.number', '205');
        $this->assertSame(0, Room::count());

        $this->postJson('/api/v1/settings/rooms/generate', $body)->assertCreated()->assertJsonPath('created', 10);
        $this->getJson('/api/v1/settings/rooms')->assertJsonCount(10, 'data')->assertJsonPath('limit', ['used' => 10, 'max' => 20]);

        // Running it again would duplicate numbers.
        $this->postJson('/api/v1/settings/rooms/generate', $body)->assertJsonValidationErrors('first_floor');
        // A type with rooms cannot be deleted.
        $this->deleteJson("/api/v1/settings/room-types/$suite")->assertJsonValidationErrors('room_type');
    }

    public function test_plan_room_limit_is_enforced(): void
    {
        $type = $this->type();
        $this->postJson('/api/v1/settings/rooms/generate', ['room_type_id' => $type, 'first_floor' => 1, 'floors' => 3, 'rooms_per_floor' => 7, 'start_number' => 1])
            ->assertJsonValidationErrors('limit'); // 21 > 20
        $this->postJson('/api/v1/settings/rooms/generate', ['room_type_id' => $type, 'first_floor' => 1, 'floors' => 2, 'rooms_per_floor' => 10, 'start_number' => 1])->assertCreated();
        $this->postJson('/api/v1/settings/rooms', ['room_type_id' => $type, 'number' => 'X1'])->assertJsonValidationErrors('limit');

        $this->assertSame(20, app(Subscriptions::class)->billableRooms($this->hotel));
        $this->getJson('/api/v1/subscription')->assertJsonPath('data.usage.rooms', 20);
    }

    public function test_single_room_edit_and_deleted_number_can_be_reused(): void
    {
        $type = $this->type();
        $id = $this->postJson('/api/v1/settings/rooms', ['room_type_id' => $type, 'number' => '101', 'floor' => '1'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/settings/rooms', ['room_type_id' => $type, 'number' => '101'])->assertJsonValidationErrors('number');

        $this->putJson("/api/v1/settings/rooms/$id", ['room_type_id' => $type, 'number' => '101', 'floor' => '1', 'status' => 'maintenance'])
            ->assertOk()->assertJsonPath('data.status', 'maintenance');

        $this->deleteJson("/api/v1/settings/rooms/$id")->assertOk();
        $this->postJson('/api/v1/settings/rooms', ['room_type_id' => $type, 'number' => '101'])->assertCreated();
    }

    public function test_branch_manager_only_sees_own_branch(): void
    {
        $this->type();
        $other = $this->type(['branch_id' => $this->second->id, 'name' => 'Other']);
        $manager = User::factory()->withRole($this->hotel, 'branch_manager')->create();
        $manager->branches()->attach($this->main->id);

        $this->actingAs($manager)->getJson('/api/v1/settings/room-types')->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/settings/room-types', ['branch_id' => $this->second->id, 'name' => 'X', 'base_capacity' => 1, 'max_capacity' => 1, 'nightly_price' => '1', 'currency' => 'ETB'])
            ->assertJsonValidationErrors('branch_id');
        $this->postJson('/api/v1/settings/rooms', ['room_type_id' => $other, 'number' => '1'])->assertJsonValidationErrors('room_type_id');
    }

    public function test_receptionist_cannot_change_rooms(): void
    {
        $user = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $this->actingAs($user)->getJson('/api/v1/settings/rooms')->assertForbidden();
    }

    public function test_rooms_of_another_hotel_are_invisible(): void
    {
        $type = $this->type();
        $this->postJson('/api/v1/settings/rooms', ['room_type_id' => $type, 'number' => '101'])->assertCreated();
        $id = Room::first()->id;

        $otherHotel = Hotel::factory()->create(['plan_id' => $this->hotel->plan_id]);
        Branch::factory()->for($otherHotel)->create();
        $stranger = User::factory()->withRole($otherHotel, 'hotel_admin')->create();

        $this->actingAs($stranger)->getJson('/api/v1/settings/rooms')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/settings/room-types')->assertJsonCount(0, 'data');
        $this->putJson("/api/v1/settings/rooms/$id", ['room_type_id' => $type, 'number' => '9', 'status' => 'available'])->assertNotFound();
        $this->deleteJson("/api/v1/settings/room-types/$type")->assertNotFound();
        $this->postJson('/api/v1/settings/rooms', ['room_type_id' => $type, 'number' => '5'])->assertJsonValidationErrors('room_type_id');
    }

    public function test_rate_rules_and_price_preview(): void
    {
        $type = $this->type();
        $corp = $this->postJson('/api/v1/settings/guest-types', ['name' => 'Corporate'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/settings/rate-rules', ['name' => 'Weekend', 'rule_type' => 'weekend', 'adjustment' => 'percent', 'value' => '20', 'priority' => 10])
            ->assertJsonValidationErrors('days_of_week');
        $this->postJson('/api/v1/settings/rate-rules', ['name' => 'Weekend', 'rule_type' => 'weekend', 'days_of_week' => [5, 6], 'adjustment' => 'percent', 'value' => '20', 'priority' => 10, 'is_active' => true])->assertCreated();
        $this->postJson('/api/v1/settings/rate-rules', ['name' => 'Corp', 'rule_type' => 'guest_type', 'guest_type_id' => $corp, 'adjustment' => 'amount', 'value' => '-500', 'priority' => 20, 'is_active' => true])->assertCreated();

        // Thu 2026-11-12 → Sat 2026-11-14: Thursday 2500 + Friday 3000.
        $this->getJson("/api/v1/settings/price-preview?room_type_id=$type&from=2026-11-12&to=2026-11-14")
            ->assertOk()->assertJsonPath('data.total', '5500.00')->assertJsonPath('data.nights.1.rule.name', 'Weekend');
        $this->getJson("/api/v1/settings/price-preview?room_type_id=$type&from=2026-11-12&to=2026-11-14&guest_type_id=$corp")
            ->assertJsonPath('data.total', '4000.00');

        $this->deleteJson("/api/v1/settings/guest-types/$corp")->assertJsonValidationErrors('guest_type');
    }
}
