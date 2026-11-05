<?php

namespace Tests\Feature\Admin;

use App\Mail\HotelAdminWelcome;
use App\Models\Branch;
use App\Models\Hotel;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HotelManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlatformSeeder::class);
        $this->owner = User::factory()->superAdmin()->create();
    }

    private function newHotel(array $overrides = []): array
    {
        return [
            'name' => 'Sheger Hotel',
            'email' => 'info@sheger.test',
            'phone' => '+251911000000',
            'city' => 'Addis Ababa',
            'default_currency' => 'ETB',
            'plan_id' => Plan::where('code', 'standard')->value('id'),
            'billing_cycle' => 'monthly',
            'admin' => ['name' => 'Meron Tesfaye', 'email' => 'meron@sheger.test'],
            ...$overrides,
        ];
    }

    public function test_creating_a_hotel_sets_up_branch_admin_and_trial(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/admin/hotels', $this->newHotel(['trial_days' => 14]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'trial')
            ->assertJsonPath('data.trial_ends_at', now()->addDays(14)->toDateString())
            ->assertJsonPath('admin.email', 'meron@sheger.test')
            ->assertJsonPath('admin.email_sent', true);

        $hotel = Hotel::where('name', 'Sheger Hotel')->firstOrFail();
        $admin = User::where('email', 'meron@sheger.test')->firstOrFail();

        $this->assertSame($hotel->id, $admin->hotel_id);
        $this->assertTrue($admin->must_change_password);
        $this->assertFalse($admin->is_super_admin);
        $this->assertSame(1, $hotel->branches()->count());
        $this->assertSame('Pro', $hotel->trialPlan->name); // default trial plan
        $this->assertNotEmpty($response->json('admin.temporary_password'));

        Mail::assertSent(HotelAdminWelcome::class, fn ($mail) => $mail->hasTo('meron@sheger.test'));
    }

    public function test_new_hotel_uses_the_default_trial_length(): void
    {
        Mail::fake();
        $this->actingAs($this->owner)->putJson('/api/v1/admin/settings', ['default_trial_days' => 45])->assertOk();

        $this->postJson('/api/v1/admin/hotels', $this->newHotel())
            ->assertCreated()
            ->assertJsonPath('data.trial_ends_at', now()->addDays(45)->toDateString());
    }

    public function test_hotel_admin_email_must_be_unique_on_the_platform(): void
    {
        User::factory()->create(['email' => 'meron@sheger.test']);

        $this->actingAs($this->owner)->postJson('/api/v1/admin/hotels', $this->newHotel())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('admin.email');
    }

    public function test_the_new_hotel_admin_only_sees_their_own_hotel(): void
    {
        Mail::fake();
        $other = Hotel::factory()->create();
        Branch::factory()->for($other)->create(['name' => 'Other branch']);

        $this->actingAs($this->owner)->postJson('/api/v1/admin/hotels', $this->newHotel())->assertCreated();

        $admin = User::where('email', 'meron@sheger.test')->firstOrFail();
        $admin->forceFill(['must_change_password' => false])->save();

        $this->actingAs($admin)->getJson('/api/v1/branches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Main branch');
    }

    public function test_hotel_list_with_search_and_status_filter(): void
    {
        Hotel::factory()->create(['name' => 'Sheger Hotel', 'status' => 'active']);
        Hotel::factory()->create(['name' => 'Lalibela Lodge', 'status' => 'suspended']);

        $this->actingAs($this->owner)->getJson('/api/v1/admin/hotels?search=sheger')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Sheger Hotel');

        $this->getJson('/api/v1/admin/hotels?status=suspended')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Lalibela Lodge')
            ->assertJsonPath('counts.active', 1);
    }

    public function test_extending_a_trial_for_one_hotel(): void
    {
        $hotel = Hotel::factory()->create(['status' => 'grace', 'subscription_ends_at' => null, 'trial_ends_at' => now()->subDay()]);

        $this->actingAs($this->owner)->postJson("/api/v1/admin/hotels/{$hotel->id}/trial", [
            'trial_ends_at' => now()->addDays(10)->toDateString(),
            'reason' => 'Needs more time to set up',
        ])->assertOk()->assertJsonPath('data.status', 'trial');
    }

    public function test_suspend_makes_the_hotel_read_only_and_activate_restores_it(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'tenant', 'hotel.writable'])
            ->post('/api/v1/_test/save', fn () => ['saved' => true]);

        $hotel = Hotel::factory()->create(['status' => 'active']);
        $staff = User::factory()->forHotel($hotel)->create();

        $this->actingAs($this->owner)->postJson("/api/v1/admin/hotels/{$hotel->id}/suspend", ['reason' => 'Fraud check'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');

        // Reading still works, saving is blocked.
        $this->actingAs($staff->fresh())->getJson('/api/v1/branches')->assertOk();
        $this->postJson('/api/v1/_test/save')->assertStatus(423)->assertJsonPath('code', 'hotel_suspended');

        $this->actingAs($this->owner)->postJson("/api/v1/admin/hotels/{$hotel->id}/activate")
            ->assertOk()->assertJsonPath('data.status', 'active');

        $this->actingAs($staff->fresh())->postJson('/api/v1/_test/save')->assertOk();
    }

    public function test_hotel_staff_cannot_use_the_super_admin_area(): void
    {
        $staff = User::factory()->forHotel(Hotel::factory()->create())->create();

        $this->actingAs($staff)->getJson('/api/v1/admin/hotels')->assertForbidden();
    }

    public function test_team_roles_limit_what_each_member_can_do(): void
    {
        $support = User::factory()->platformRole('support')->create();
        $finance = User::factory()->platformRole('finance')->create();
        $hotel = Hotel::factory()->create();

        $this->actingAs($support)->getJson('/api/v1/admin/hotels')->assertOk();
        $this->postJson('/api/v1/admin/hotels', $this->newHotel())->assertForbidden();
        $this->postJson("/api/v1/admin/hotels/{$hotel->id}/suspend", ['reason' => 'x'])->assertForbidden();

        $this->actingAs($finance)->postJson("/api/v1/admin/hotels/{$hotel->id}/suspend", ['reason' => 'Unpaid'])->assertOk();
    }

    public function test_each_team_role_gets_exactly_its_permissions(): void
    {
        $expect = [
            'support' => ['hotels.view' => true, 'hotels.create' => false, 'billing.manage' => false, 'hotels.impersonate' => true],
            'sales' => ['hotels.view' => true, 'hotels.create' => true, 'billing.manage' => false, 'hotels.impersonate' => false],
            'finance' => ['hotels.view' => true, 'hotels.create' => false, 'billing.manage' => true, 'hotels.impersonate' => false],
            'tech' => ['hotels.view' => true, 'hotels.create' => false, 'billing.manage' => false, 'switches.manage' => true],
            'owner' => ['hotels.view' => true, 'hotels.create' => true, 'billing.manage' => true, 'team.manage' => true],
        ];

        foreach ($expect as $role => $permissions) {
            $member = User::factory()->platformRole($role)->make();
            foreach ($permissions as $permission => $allowed) {
                $this->assertSame($allowed, $member->hasPlatformPermission($permission), "{$role} / {$permission}");
            }
        }

        $this->assertFalse(User::factory()->make()->hasPlatformPermission('hotels.view'));
    }
}
