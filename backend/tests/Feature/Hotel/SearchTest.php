<?php

namespace Tests\Feature\Hotel;

use App\Models\Branch;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Plan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\HotelDefaults;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    private function hotelWith(string $name, string $guest, string $room): array
    {
        $hotel = Hotel::factory()->create(['name' => $name, 'plan_id' => Plan::factory()->create(['max_rooms' => null, 'modules' => []])->id]);
        $branch = Branch::factory()->for($hotel)->create();
        app(HotelDefaults::class)->apply($hotel);
        app(TenantContext::class)->runAs($hotel, function () use ($branch, $guest, $room) {
            $type = RoomType::create(['branch_id' => $branch->id, 'name' => 'Standard', 'base_capacity' => 2, 'max_capacity' => 2, 'nightly_price' => '2000', 'currency' => 'ETB']);
            Room::create(['branch_id' => $branch->id, 'room_type_id' => $type->id, 'number' => $room, 'status' => 'available']);
            Guest::create(['first_name' => $guest, 'last_name' => 'Tesfaye', 'phone' => '+251 911 000000']);
        });

        return [$hotel, $branch];
    }

    public function test_finds_guests_and_rooms_of_your_own_hotel_only(): void
    {
        [$a] = $this->hotelWith('Sheba', 'Almaz', '301');
        $this->hotelWith('Other', 'Almaz', '302');
        $admin = User::factory()->withRole($a, 'hotel_admin')->create();

        $hits = $this->actingAs($admin)->getJson('/api/v1/search?q=Almaz')->assertOk()->json('data');
        $this->assertCount(1, array_filter($hits, fn ($h) => $h['type'] === 'guest'));

        $rooms = $this->actingAs($admin)->getJson('/api/v1/search?q=30')->assertOk()->json('data');
        $this->assertSame(['Room 301'], array_column(array_filter($rooms, fn ($h) => $h['type'] === 'room'), 'title'));
    }

    public function test_only_shows_what_your_role_may_see(): void
    {
        [$a] = $this->hotelWith('Sheba', 'Almaz', '301');
        $cleaner = User::factory()->withRole($a, 'housekeeping')->create();

        $this->actingAs($cleaner)->getJson('/api/v1/search?q=Almaz')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_needs_two_letters_and_escapes_wildcards(): void
    {
        [$a] = $this->hotelWith('Sheba', 'Almaz', '301');
        $admin = User::factory()->withRole($a, 'hotel_admin')->create();

        $this->actingAs($admin)->getJson('/api/v1/search?q=A')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/api/v1/search?q=%25%25')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_super_admin_searches_hotels(): void
    {
        $this->hotelWith('Sheba Palace', 'Almaz', '301');
        $sa = User::factory()->superAdmin()->create();

        $hits = $this->actingAs($sa)->getJson('/api/v1/search?q=Sheba')->assertOk()->json('data');
        $this->assertSame('hotel', $hits[0]['type']);
        $this->assertSame('Sheba Palace', $hits[0]['title']);
    }
}
