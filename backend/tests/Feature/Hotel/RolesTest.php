<?php

namespace Tests\Feature\Hotel;

use App\Models\Hotel;
use App\Models\Role;
use App\Models\User;
use App\Services\DefaultRoles;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hotel = Hotel::factory()->create();
    }

    public function test_every_hotel_gets_the_five_default_roles_with_approved_permissions(): void
    {
        $roles = app(DefaultRoles::class)->ensureFor($this->hotel);

        $this->assertSame(['hotel_admin', 'branch_manager', 'receptionist', 'housekeeping', 'accountant', 'restaurant'], array_keys($roles));
        $this->assertSame(['attendance.clock', 'charges.room', 'shifts.view_own'], collect($roles['restaurant']->permissionKeys())->sort()->values()->all()); // Restaurant / Bar: only charges rooms
        $this->assertSame(Permissions::all(), $roles['hotel_admin']->permissionKeys());
        $this->assertTrue($roles['hotel_admin']->is_locked);
        $this->assertSame('20.00', $roles['branch_manager']->max_discount_percent);
        $this->assertSame('5.00', $roles['receptionist']->max_discount_percent);

        $rc = $roles['receptionist']->permissionKeys();
        $this->assertContains('frontdesk.check_in', $rc);
        $this->assertNotContains('invoices.void', $rc);
        $this->assertNotContains('guests.export', $roles['branch_manager']->permissionKeys());
        $this->assertSame(['housekeeping.own_tasks', 'housekeeping.view', 'rooms.change_status', 'shifts.view_own', 'attendance.clock'],
            array_values(array_intersect(['housekeeping.own_tasks', 'housekeeping.view', 'rooms.change_status', 'shifts.view_own', 'attendance.clock'], $roles['housekeeping']->permissionKeys())));
    }

    public function test_hotel_admin_creates_a_custom_role(): void
    {
        $admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();

        $this->actingAs($admin)->postJson('/api/v1/roles', [
            'name' => 'Night auditor',
            'max_discount_percent' => '0',
            'permissions' => ['reservations.view', 'reports.audit'],
        ])->assertCreated()->assertJsonPath('data.permissions', ['reports.audit', 'reservations.view']);
    }

    public function test_hotel_admin_role_is_locked(): void
    {
        $admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();
        $locked = Role::withoutGlobalScope('hotel')->where('hotel_id', $this->hotel->id)->where('key', 'hotel_admin')->first();

        $this->actingAs($admin)->putJson("/api/v1/roles/{$locked->id}", [
            'name' => 'Boss', 'max_discount_percent' => '100', 'permissions' => [],
        ])->assertUnprocessable();
    }

    public function test_roles_of_another_hotel_cannot_be_reached(): void
    {
        $admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();
        $other = Hotel::factory()->create();
        $foreign = app(DefaultRoles::class)->ensureFor($other)['receptionist'];

        $this->actingAs($admin)->getJson("/api/v1/roles/{$foreign->id}")->assertNotFound();
        $this->putJson("/api/v1/roles/{$foreign->id}", ['name' => 'x', 'max_discount_percent' => '0', 'permissions' => []])->assertNotFound();
    }

    public function test_staff_without_roles_manage_cannot_edit_roles(): void
    {
        $manager = User::factory()->withRole($this->hotel, 'branch_manager')->create();

        $this->actingAs($manager)->postJson('/api/v1/roles', ['name' => 'X', 'max_discount_percent' => '0', 'permissions' => []])
            ->assertForbidden();
    }

    public function test_a_role_in_use_cannot_be_deleted(): void
    {
        $admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();
        $receptionist = User::factory()->withRole($this->hotel, 'receptionist')->create();

        $this->actingAs($admin)->deleteJson("/api/v1/roles/{$receptionist->role_id}")->assertUnprocessable();
    }

    public function test_changing_permissions_is_written_to_the_activity_log(): void
    {
        $admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();
        $role = app(DefaultRoles::class)->ensureFor($this->hotel)['receptionist'];

        $this->actingAs($admin)->putJson("/api/v1/roles/{$role->id}", [
            'name' => 'Receptionist',
            'max_discount_percent' => '5',
            'permissions' => ['frontdesk.view'],
        ])->assertOk();

        $this->getJson('/api/v1/activity-log')
            ->assertOk()
            ->assertJsonFragment(['description' => 'Permissions changed: Receptionist']);
    }
}
