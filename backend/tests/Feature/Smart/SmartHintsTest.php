<?php

namespace Tests\Feature\Smart;

use App\Models\Branch;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\HotelDefaults;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SmartHintsTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private Branch $branch;

    private User $admin;

    private RoomType $standard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 09:00', 'Africa/Addis_Ababa'));
        [$this->hotel, $this->branch, $this->admin] = $this->hotelWithStaff('Sheba');
        $this->inHotel($this->hotel, function () {
            $this->standard = RoomType::create(['branch_id' => $this->branch->id, 'name' => 'Standard', 'base_capacity' => 2, 'max_capacity' => 2, 'nightly_price' => '2000', 'currency' => 'ETB']);
            foreach (['101', '102', '103'] as $n) {
                Room::create(['branch_id' => $this->branch->id, 'room_type_id' => $this->standard->id, 'number' => $n, 'status' => 'available']);
            }
            Guest::create(['first_name' => 'Almaz', 'last_name' => 'Tesfaye', 'phone' => '+251 911 223344']);
        });
    }

    private function hotelWithStaff(string $name): array
    {
        $hotel = Hotel::factory()->create(['name' => $name, 'status' => 'active', 'plan_id' => Plan::factory()->create(['max_rooms' => 4, 'modules' => []])->id]);
        $branch = Branch::factory()->for($hotel)->create(['check_in_time' => '14:00', 'check_out_time' => '12:00']);
        app(HotelDefaults::class)->apply($hotel);

        return [$hotel, $branch, User::factory()->withRole($hotel, 'hotel_admin')->create()];
    }

    private function inHotel(Hotel $hotel, callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($hotel, $fn);
    }

    public function test_duplicate_guest_is_found_by_phone_in_your_hotel_only(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/smart/guest_check?phone=0911223344')->assertOk()
            ->assertJsonPath('data.0.key', 'duplicate_guest')->assertJsonPath('data.0.data.name', 'Almaz Tesfaye');
        $this->getJson('/api/v1/smart/guest_check?phone=0911999999')->assertOk()->assertJsonCount(0, 'data');

        [, , $stranger] = $this->hotelWithStaff('Other');
        $this->actingAs($stranger)->getJson('/api/v1/smart/guest_check?phone=0911223344')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_dashboard_tonight_and_plan_usage(): void
    {
        $tonight = collect($this->actingAs($this->admin)->getJson('/api/v1/smart/dashboard')->assertOk()->assertJsonFragment(['key' => 'tonight'])->json('data'))->firstWhere('key', 'tonight');
        // "Why?" has the full explanation: the numbers, how it was worked out.
        $this->assertContains(['Rooms in the hotel', (string) $this->inHotel($this->hotel, fn () => Room::count())], $tonight['explain']['facts']);
        $this->assertNotEmpty($tonight['explain']['how']);
        $this->getJson('/api/v1/smart/rooms_settings')->assertOk()->assertJsonPath('data.0.text', 'You can add 1 more room on your plan.');
    }

    public function test_tonight_does_not_count_guests_leaving_today(): void
    {
        $this->actingAs($this->admin);
        $guest = $this->inHotel($this->hotel, fn () => Guest::first());
        $cash = $this->inHotel($this->hotel, fn () => PaymentMethod::where('type', 'cash')->value('id'));
        $walkIn = fn (string $room, string $out) => $this->postJson('/api/v1/front-desk/walk-in', [
            'branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_out' => $out, 'guest_id' => $guest->id,
            'rooms' => [['room_type_id' => $this->standard->id, 'room_id' => $this->inHotel($this->hotel, fn () => Room::where('number', $room)->value('id'))]],
            'payment' => ['payment_method_id' => $cash, 'amount' => '99999', 'confirm_overpay' => true],
        ])->assertCreated();

        $this->travelTo(Carbon::parse('2026-11-09 15:00', 'Africa/Addis_Ababa'));
        $walkIn('101', '2026-11-10'); // leaves today at 12:00
        $this->travelTo(Carbon::parse('2026-11-10 09:00', 'Africa/Addis_Ababa'));
        $walkIn('102', '2026-11-11'); // stays tonight

        $tonight = collect($this->getJson('/api/v1/smart/dashboard')->json('data'))->firstWhere('key', 'tonight');
        $this->assertContains(['Rooms with a guest staying tonight', '1'], $tonight['explain']['facts']);
    }

    public function test_hotelms_can_switch_a_hint_off_and_roles_are_checked(): void
    {
        $owner = User::factory()->superAdmin()->create();
        $this->actingAs($owner)->postJson('/api/v1/admin/switches', ['key' => 'smart.tonight', 'level' => 'hotel', 'target_id' => $this->hotel->id, 'value' => false])->assertCreated();
        $this->actingAs($this->admin)->getJson('/api/v1/smart/dashboard')->assertOk()->assertJsonMissing(['key' => 'tonight']);

        $cleaner = User::factory()->withRole($this->hotel, 'housekeeping')->create();
        $this->actingAs($cleaner)->getJson('/api/v1/smart/guest_check?phone=0911223344')->assertForbidden();
        $this->getJson('/api/v1/smart/nonsense')->assertNotFound();
        $this->actingAs($owner)->getJson('/api/v1/smart/admin')->assertOk();
    }

    public function test_the_search_box_answers_questions(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/search?q=free rooms tomorrow')->assertOk()
            ->assertJsonPath('data.0.type', 'answer')->assertJsonPath('data.0.title', '3 free rooms tomorrow');
        $this->getJson('/api/v1/search?q=who is in room 101')->assertOk()->assertJsonPath('data.0.title', 'Nobody is in room 101 now');
        $this->getJson('/api/v1/search?q=unpaid over 500')->assertOk()->assertJsonPath('data.0.title', '0 unpaid bills over 500');
    }
}
