<?php

namespace Tests\Feature\Hotel;

use App\Models\Branch;
use App\Models\Hotel;
use App\Models\User;
use App\Services\HotelDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Getting started" checklist on a new hotel's dashboard (owner, 14 Nov 2026). */
class GettingStartedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_checklist_follows_the_hotels_own_data_and_can_be_hidden(): void
    {
        $hotel = Hotel::factory()->create(['status' => 'active']);
        app(HotelDefaults::class)->apply($hotel);
        Branch::factory()->for($hotel)->create();
        $admin = User::factory()->withRole($hotel, 'hotel_admin')->create();

        $r = $this->actingAs($admin)->getJson('/api/v1/getting-started')->assertOk();
        $this->assertSame(8, $r->json('data.total'));
        $this->assertSame(0, $r->json('data.done'));
        $this->assertFalse($r->json('data.dismissed'));

        User::factory()->withRole($hotel, 'receptionist')->create();
        $hotel->forceFill(['setup_completed_at' => now(), 'logo_path' => 'logos/x.png'])->save();
        $admin->unsetRelation('hotel');
        $items = collect($this->getJson('/api/v1/getting-started')->json('data.items'))->pluck('done', 'key');
        $this->assertTrue($items['staff']);
        $this->assertTrue($items['setup']);
        $this->assertTrue($items['logo']);
        $this->assertFalse($items['booking']);

        $this->postJson('/api/v1/getting-started/dismiss')->assertOk()->assertJsonPath('data.dismissed', true);
        $this->getJson('/api/v1/getting-started')->assertJsonPath('data.dismissed', true);
    }

    public function test_only_the_hotel_admin_sees_it_and_each_hotel_has_its_own(): void
    {
        $hotel = Hotel::factory()->create();
        app(HotelDefaults::class)->apply($hotel);
        $rc = User::factory()->withRole($hotel, 'receptionist')->create();
        $this->actingAs($rc)->getJson('/api/v1/getting-started')->assertForbidden();

        $other = Hotel::factory()->create();
        app(HotelDefaults::class)->apply($other);
        $otherAdmin = User::factory()->withRole($other, 'hotel_admin')->create();
        $admin = User::factory()->withRole($hotel, 'hotel_admin')->create();
        $this->actingAs($admin)->postJson('/api/v1/getting-started/dismiss')->assertOk();
        $this->actingAs($otherAdmin)->getJson('/api/v1/getting-started')->assertJsonPath('data.dismissed', false);
    }
}
