<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\DataAccessLog;
use App\Models\Hotel;
use App\Models\Plan;
use App\Models\User;
use App\Services\HotelDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamAndSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_adds_a_team_member_and_changes_their_role(): void
    {
        $owner = User::factory()->superAdmin()->create();
        $res = $this->actingAs($owner)->postJson('/api/v1/admin/team', ['name' => 'Hana', 'email' => 'hana@hotelms.test', 'role' => 'support'])->assertCreated();
        $this->assertNotEmpty($res->json('temporary_password'));
        $hana = User::where('email', 'hana@hotelms.test')->firstOrFail();
        $this->assertTrue($hana->is_super_admin);
        $this->assertNull($hana->hotel_id);
        $this->assertTrue($hana->must_change_password);

        $this->putJson("/api/v1/admin/team/{$hana->id}", ['role' => 'finance'])->assertOk();
        $this->assertSame('finance', $hana->fresh()->platform_role);
        $this->putJson("/api/v1/admin/team/{$hana->id}", ['status' => 'disabled'])->assertOk();
        $this->assertSame('disabled', $hana->fresh()->status);
    }

    public function test_only_the_owner_manages_the_team_and_there_is_always_an_owner(): void
    {
        $owner = User::factory()->superAdmin()->create();
        $tech = User::factory()->platformRole('tech')->create();
        $this->actingAs($tech)->getJson('/api/v1/admin/team')->assertForbidden();

        $this->actingAs($owner)->putJson("/api/v1/admin/team/{$owner->id}", ['role' => 'tech'])->assertUnprocessable();
        $second = User::factory()->superAdmin()->create(['status' => 'disabled']);
        $this->putJson("/api/v1/admin/team/{$second->id}", ['status' => 'active'])->assertOk();
        $this->putJson("/api/v1/admin/team/{$second->id}", ['role' => 'support'])->assertOk(); // the first owner is still there
    }

    public function test_the_owner_changes_what_a_role_may_do(): void
    {
        $owner = User::factory()->superAdmin()->create();
        $sales = User::factory()->platformRole('sales')->create();
        $this->actingAs($sales)->getJson('/api/v1/admin/system')->assertForbidden();

        $map = $this->actingAs($owner)->getJson('/api/v1/admin/team')->assertOk()->json('permissions');
        $map['system.view'][] = 'sales';
        $saved = $this->putJson('/api/v1/admin/team/permissions', ['permissions' => $map])->assertOk()->json('permissions');
        $this->assertSame(['sales', 'tech'], $saved['system.view']);

        $this->actingAs($sales)->getJson('/api/v1/admin/system')->assertOk()->assertJsonStructure(['data' => ['checks' => ['database', 'cache', 'scheduler', 'jobs', 'backups', 'disk'], 'errors', 'versions']]);
        $this->putJson('/api/v1/admin/team/permissions', ['permissions' => $map])->assertForbidden();
    }

    public function test_looking_at_live_data_needs_a_reason_and_is_logged_for_the_hotel(): void
    {
        $hotel = Hotel::factory()->create(['plan_id' => Plan::factory()->create()->id]);
        app(HotelDefaults::class)->apply($hotel);
        $support = User::factory()->platformRole('support')->create();

        $this->actingAs($support)->postJson("/api/v1/admin/hotels/{$hotel->id}/live", [])->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/admin/hotels/{$hotel->id}/live", ['reason' => 'They asked about occupancy'])->assertOk()
            ->assertJsonStructure(['data' => ['today' => ['rooms', 'occupied', 'occupancy', 'arrivals', 'departures', 'bookings_made', 'payments', 'staff_online'], 'activity']]);
        $this->postJson("/api/v1/admin/hotels/{$hotel->id}/live", ['reason' => 'Again'])->assertOk();

        $this->assertSame(1, DataAccessLog::where('hotel_id', $hotel->id)->count()); // once per half hour
        $this->assertTrue(ActivityLog::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->where('event', 'support.live_data')->exists());
        $this->getJson("/api/v1/admin/hotels/{$hotel->id}/overview")->assertOk()->assertJsonPath('data.access.0.reason', 'They asked about occupancy');
    }

    public function test_notes_about_a_hotel(): void
    {
        $hotel = Hotel::factory()->create(['plan_id' => Plan::factory()->create()->id]);
        $support = User::factory()->platformRole('support')->create();
        $sales = User::factory()->platformRole('sales')->create();

        $id = $this->actingAs($support)->postJson("/api/v1/admin/hotels/{$hotel->id}/notes", ['body' => 'Owner prefers Telegram.', 'channel' => 'phone'])->assertCreated()->json('data.id');
        $this->getJson("/api/v1/admin/hotels/{$hotel->id}/overview")->assertJsonPath('data.notes.0.body', 'Owner prefers Telegram.');
        $this->actingAs($sales)->deleteJson("/api/v1/admin/hotels/{$hotel->id}/notes/{$id}")->assertForbidden();
        $this->actingAs($support)->deleteJson("/api/v1/admin/hotels/{$hotel->id}/notes/{$id}")->assertOk();
    }

    public function test_command_center_numbers(): void
    {
        $owner = User::factory()->superAdmin()->create();
        $this->actingAs($owner)->getJson('/api/v1/admin/dashboard')->assertOk()
            ->assertJsonStructure(['today' => ['bookings', 'money', 'occupancy', 'staff_online', 'open_support'], 'top_hotels', 'activity']);
        $this->getJson('/api/v1/admin/activity')->assertOk()->assertJsonStructure(['data', 'meta']);
    }
}
