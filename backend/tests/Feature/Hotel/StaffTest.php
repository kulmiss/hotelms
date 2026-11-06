<?php

namespace Tests\Feature\Hotel;

use App\Mail\StaffInvite;
use App\Models\Branch;
use App\Models\Hotel;
use App\Models\Role;
use App\Models\User;
use App\Services\DefaultRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StaffTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private Branch $bole;

    private Branch $piassa;

    /** @var array<string, Role> */
    private array $roles;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->hotel = Hotel::factory()->create();
        $this->bole = Branch::factory()->for($this->hotel)->create(['name' => 'Bole']);
        $this->piassa = Branch::factory()->for($this->hotel)->create(['name' => 'Piassa']);
        $this->roles = app(DefaultRoles::class)->ensureFor($this->hotel);
    }

    private function admin(): User
    {
        return User::factory()->withRole($this->hotel, 'hotel_admin')->create();
    }

    private function manager(Branch $branch): User
    {
        $m = User::factory()->withRole($this->hotel, 'branch_manager')->create();
        $m->branches()->attach($branch);

        return $m;
    }

    public function test_hotel_admin_adds_staff_with_a_temporary_password(): void
    {
        $response = $this->actingAs($this->admin())->postJson('/api/v1/staff', [
            'name' => 'Abebe',
            'email' => 'abebe@sheger.test',
            'role_id' => $this->roles['receptionist']->id,
            'branch_ids' => [$this->bole->id],
        ])->assertCreated()
            ->assertJsonPath('data.role.name', 'Receptionist')
            ->assertJsonPath('data.branches.0.name', 'Bole')
            ->assertJsonPath('email_sent', true);

        $user = User::where('email', 'abebe@sheger.test')->firstOrFail();
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->hasPermission('frontdesk.check_in'));
        $this->assertNotEmpty($response->json('temporary_password'));
        Mail::assertSent(StaffInvite::class);
    }

    public function test_staff_only_see_their_assigned_branches(): void
    {
        $rc = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $rc->branches()->attach($this->piassa);

        $this->actingAs($rc)->getJson('/api/v1/me')->assertJsonCount(1, 'branches')->assertJsonPath('branches.0.name', 'Piassa');
        $this->actingAs($this->admin())->getJson('/api/v1/me')->assertJsonCount(2, 'branches');
    }

    public function test_branch_manager_only_manages_staff_of_their_branch(): void
    {
        $manager = $this->manager($this->bole);
        $boleStaff = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $boleStaff->branches()->attach($this->bole);
        $piassaStaff = User::factory()->withRole($this->hotel, 'receptionist')->create(['name' => 'Piassa person']);
        $piassaStaff->branches()->attach($this->piassa);

        $this->actingAs($manager)->getJson('/api/v1/staff')
            ->assertOk()
            ->assertJsonMissing(['name' => 'Piassa person']);

        $this->postJson("/api/v1/staff/{$piassaStaff->id}/disable")->assertNotFound();
        $this->postJson("/api/v1/staff/{$boleStaff->id}/disable")->assertOk()->assertJsonPath('data.status', 'disabled');

        // Cannot hire into a branch they don't work in.
        $this->postJson('/api/v1/staff', [
            'name' => 'X', 'email' => 'x@sheger.test', 'role_id' => $this->roles['receptionist']->id, 'branch_ids' => [$this->piassa->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_ids');
    }

    public function test_branch_manager_cannot_give_roles_above_their_own(): void
    {
        $this->actingAs($this->manager($this->bole))->postJson('/api/v1/staff', [
            'name' => 'X', 'email' => 'x@sheger.test', 'role_id' => $this->roles['hotel_admin']->id, 'branch_ids' => [$this->bole->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('role_id');
    }

    public function test_the_plan_limits_active_staff_accounts(): void
    {
        $admin = $this->admin();
        $plan = \App\Models\Plan::factory()->create(['max_staff' => 2, 'modules' => []]);
        $this->hotel->forceFill(['plan_id' => $plan->id, 'status' => 'active'])->save();
        $role = $this->roles['receptionist'];
        $new = fn (string $email) => $this->actingAs($admin)->postJson('/api/v1/staff', ['name' => 'New', 'email' => $email, 'role_id' => $role->id, 'branch_ids' => [$this->bole->id], 'send_email' => false]);

        $this->assertSame(1, User::where('hotel_id', $this->hotel->id)->where('status', 'active')->count());
        $other = $new('one@sheger.test')->assertCreated()->json('data.id');
        $new('two@sheger.test')->assertJsonValidationErrors('email'); // 2 of 2 used
        $this->postJson("/api/v1/staff/$other/disable")->assertOk();
        $new('two@sheger.test')->assertCreated();
        $this->postJson("/api/v1/staff/$other/enable")->assertJsonValidationErrors('email');
    }

    public function test_branch_manager_cannot_take_over_someone_who_can_do_more(): void
    {
        $manager = $this->manager($this->bole);
        $accountant = User::factory()->withRole($this->hotel, 'accountant')->create(); // tax and accounting reports
        $accountant->branches()->attach($this->bole);

        $this->actingAs($manager)->postJson("/api/v1/staff/{$accountant->id}/reset-password")->assertForbidden();
        $this->postJson("/api/v1/staff/{$accountant->id}/disable")->assertForbidden();
        $this->actingAs($this->admin())->postJson("/api/v1/staff/{$accountant->id}/reset-password")->assertOk();
    }

    public function test_nobody_disables_themselves_or_changes_their_own_role(): void
    {
        $admin = $this->admin();
        User::factory()->withRole($this->hotel, 'hotel_admin')->create(); // a second admin exists

        $this->actingAs($admin)->postJson("/api/v1/staff/{$admin->id}/disable")->assertUnprocessable();
        $this->putJson("/api/v1/staff/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'role_id' => $this->roles['receptionist']->id, 'branch_ids' => [$this->bole->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('role_id');
    }

    public function test_a_disabled_account_is_shut_out_immediately(): void
    {
        $rc = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $rc->branches()->attach($this->bole);
        $rc->forceFill(['status' => 'disabled'])->save();

        $this->actingAs($rc)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_staff_of_another_hotel_cannot_be_reached(): void
    {
        $stranger = User::factory()->withRole(Hotel::factory()->create(), 'receptionist')->create();

        $this->actingAs($this->admin())->getJson("/api/v1/staff/{$stranger->id}")->assertNotFound();
        $this->postJson("/api/v1/staff/{$stranger->id}/disable")->assertNotFound();
    }

    public function test_receptionist_cannot_see_staff_list(): void
    {
        $rc = User::factory()->withRole($this->hotel, 'receptionist')->create();

        $this->actingAs($rc)->getJson('/api/v1/staff')->assertForbidden();
    }

    public function test_activity_log_records_changes_without_passwords_and_stays_in_the_hotel(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/api/v1/staff', [
            'name' => 'Abebe', 'email' => 'abebe@sheger.test', 'role_id' => $this->roles['receptionist']->id, 'branch_ids' => [$this->bole->id],
        ])->assertCreated();

        $log = $this->getJson('/api/v1/activity-log')->assertOk()->json('data');
        $this->assertContains('User created: Abebe', array_column($log, 'description'));
        $this->assertStringNotContainsString('"password"', json_encode($log));

        $otherAdmin = User::factory()->withRole(Hotel::factory()->create(), 'hotel_admin')->create();
        $this->actingAs($otherAdmin)->getJson('/api/v1/activity-log')->assertJsonMissing(['description' => 'User created: Abebe']);
    }
}
