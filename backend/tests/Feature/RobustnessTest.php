<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\User;
use App\Services\HotelDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Broken or hand-made requests get a plain answer, never a server error (launch recheck). */
class RobustnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_list_in_a_filter_is_not_a_server_error(): void
    {
        $hotel = Hotel::factory()->create(['status' => 'active']);
        app(HotelDefaults::class)->apply($hotel);
        $this->actingAs(User::factory()->withRole($hotel, 'hotel_admin')->create());

        foreach (['/api/v1/invoices?q[a]=1', '/api/v1/guests?q[]=a', '/api/v1/reservations?status[]=x', '/api/v1/payments?type[]=x'] as $url) {
            $this->getJson($url)->assertStatus(422)->assertJsonPath('code', 'invalid_filter');
        }
        $this->getJson('/api/v1/guests?q=a')->assertOk(); // normal filters still work
    }
}
