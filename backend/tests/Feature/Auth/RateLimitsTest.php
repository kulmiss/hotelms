<?php

namespace Tests\Feature\Auth;

use App\Models\Branch;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Limits on every signed-in call and on heavy answers (SECURITY_REPORT M5). */
class RateLimitsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $hotel = Hotel::factory()->create();
        Branch::factory()->for($hotel)->create();
        $this->admin = User::factory()->withRole($hotel, 'hotel_admin')->create();
    }

    public function test_downloads_are_limited_to_20_a_minute(): void
    {
        $this->actingAs($this->admin);
        for ($i = 0; $i < 20; $i++) {
            $this->get('/api/v1/guests?format=csv')->assertOk();
        }
        $this->getJson('/api/v1/guests?format=csv')->assertStatus(429)->assertJsonPath('code', 'too_many');
        $this->getJson('/api/v1/guests')->assertOk(); // the screen itself still works
        $this->travel(61)->seconds();
        $this->get('/api/v1/guests?format=csv')->assertOk();
    }

    public function test_reports_are_limited_to_60_a_minute(): void
    {
        $this->actingAs($this->admin);
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/reports/daily')->assertOk();
        }
        $this->getJson('/api/v1/reports/daily')->assertStatus(429);
        $this->getJson('/api/v1/me')->assertOk();
    }

    public function test_every_signed_in_call_is_limited_to_300_a_minute(): void
    {
        $this->actingAs($this->admin);
        for ($i = 0; $i < 300; $i++) {
            $this->getJson('/api/v1/notifications')->assertOk();
        }
        $this->getJson('/api/v1/me')->assertStatus(429)->assertJsonPath('code', 'too_many');
        $this->assertDatabaseHas('security_events', ['kind' => 'throttled', 'user_id' => $this->admin->id]);
    }
}
