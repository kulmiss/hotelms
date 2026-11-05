<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Hotel;
use App\Models\ImpersonationLog;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private User $hotelAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hotel = Hotel::factory()->create(['name' => 'Sheger Hotel']);
        Branch::factory()->for($this->hotel)->create();
        $this->hotelAdmin = User::factory()->withRole($this->hotel, 'hotel_admin')->create(['name' => 'Meron']);
    }

    public function test_super_admin_signs_in_as_the_hotel_admin_and_returns(): void
    {
        $owner = User::factory()->superAdmin()->create(['name' => 'Owner']);

        $this->actingAs($owner)
            ->postJson("/api/v1/admin/hotels/{$this->hotel->id}/impersonate", ['reason' => 'Help with setup'])
            ->assertOk()
            ->assertJsonPath('as.name', 'Meron');

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.name', 'Meron')
            ->assertJsonPath('hotel.name', 'Sheger Hotel')
            ->assertJsonPath('impersonation.by', 'Owner')
            ->assertJsonPath('impersonation.reason', 'Help with setup');

        // The hotel's own data is visible; the admin area is not.
        $this->getJson('/api/v1/branches')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/hotels')->assertForbidden();

        $this->postJson('/api/v1/impersonation/stop')->assertOk();
        $this->getJson('/api/v1/me')->assertJsonPath('user.name', 'Owner')->assertJsonPath('impersonation', null);

        $this->assertNotNull(ImpersonationLog::first()->ended_at);
    }

    public function test_every_support_session_is_in_the_hotels_activity_log(): void
    {
        $owner = User::factory()->superAdmin()->create(['name' => 'Owner']);
        $this->actingAs($owner)->postJson("/api/v1/admin/hotels/{$this->hotel->id}/impersonate", ['reason' => 'Check a bill']);
        $this->postJson('/api/v1/impersonation/stop');

        $hotelLog = app(TenantContext::class)->runAs($this->hotel, fn () => ActivityLog::pluck('description')->all());
        $this->assertContains('HotelMS support (Owner) signed in as Meron', $hotelLog);
        $this->assertContains('HotelMS support (Owner) left the hotel', $hotelLog);

        $platformLog = app(TenantContext::class)->acrossAllHotels(fn () => ActivityLog::whereNull('hotel_id')->pluck('description')->all());
        $this->assertContains('Signed in to Sheger Hotel as Meron', $platformLog);
    }

    public function test_a_reason_is_required(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson("/api/v1/admin/hotels/{$this->hotel->id}/impersonate", [])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_the_users_password_cannot_be_changed_during_support(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson("/api/v1/admin/hotels/{$this->hotel->id}/impersonate", ['reason' => 'Help']);

        $this->putJson('/api/v1/me/password', [
            'current_password' => 'password', 'password' => 'NewPass-2026', 'password_confirmation' => 'NewPass-2026',
        ])->assertForbidden()->assertJsonPath('code', 'impersonating');
    }

    public function test_only_support_and_tech_roles_may_sign_in_as_a_hotel(): void
    {
        $this->actingAs(User::factory()->platformRole('finance')->create())
            ->postJson("/api/v1/admin/hotels/{$this->hotel->id}/impersonate", ['reason' => 'Look'])
            ->assertForbidden();

        $this->actingAs(User::factory()->platformRole('support')->create())
            ->postJson("/api/v1/admin/hotels/{$this->hotel->id}/impersonate", ['reason' => 'Look'])
            ->assertOk();
    }

    public function test_hotel_staff_cannot_sign_in_as_other_hotels(): void
    {
        $this->actingAs($this->hotelAdmin)
            ->postJson('/api/v1/admin/hotels/'.Hotel::factory()->create()->id.'/impersonate', ['reason' => 'x'])
            ->assertForbidden();
    }
}
