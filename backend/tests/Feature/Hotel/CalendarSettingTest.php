<?php

namespace Tests\Feature\Hotel;

use App\Models\Hotel;
use App\Models\User;
use App\Services\HotelDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ethiopian calendar choice (owner, 14 Nov 2026): hotel default, each person may choose their own. */
class CalendarSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_hotel_default_and_own_choice(): void
    {
        $hotel = Hotel::factory()->create();
        app(HotelDefaults::class)->apply($hotel);
        $admin = User::factory()->withRole($hotel, 'hotel_admin')->create();
        $rc = User::factory()->withRole($hotel, 'receptionist')->create();

        $this->actingAs($admin)->getJson('/api/v1/me')->assertJsonPath('hotel.calendar', 'gregorian')->assertJsonPath('user.calendar', null);
        $profile = $this->getJson('/api/v1/settings/hotel')->assertOk()->json('data');
        $this->putJson('/api/v1/settings/hotel', ['calendar' => 'maybe'] + $profile)->assertJsonValidationErrors('calendar');
        $this->putJson('/api/v1/settings/hotel', ['calendar' => 'both'] + $profile)->assertOk()->assertJsonPath('data.calendar', 'both');
        $this->getJson('/api/v1/me')->assertJsonPath('hotel.calendar', 'both');

        // A receptionist sees the hotel's choice and can pick their own, but not change the hotel's.
        $this->actingAs($rc)->getJson('/api/v1/me')->assertJsonPath('hotel.calendar', 'both');
        $this->putJson('/api/v1/me', ['calendar' => 'ethiopian'])->assertOk()->assertJsonPath('user.calendar', 'ethiopian');
        $this->putJson('/api/v1/me', ['calendar' => null])->assertOk()->assertJsonPath('user.calendar', null);
        $this->putJson('/api/v1/me', ['calendar' => 'lunar'])->assertJsonValidationErrors('calendar');
        $this->putJson('/api/v1/settings/hotel', ['calendar' => 'gregorian'] + $profile)->assertForbidden();

        // Another hotel keeps its own default.
        $other = Hotel::factory()->create();
        app(HotelDefaults::class)->apply($other);
        $this->actingAs(User::factory()->withRole($other, 'hotel_admin')->create())->getJson('/api/v1/me')->assertJsonPath('hotel.calendar', 'gregorian');
    }
}
