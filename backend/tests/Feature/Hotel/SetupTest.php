<?php

namespace Tests\Feature\Hotel;

use App\Models\Branch;
use App\Models\Hotel;
use App\Models\Plan;
use App\Models\User;
use App\Services\HotelDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $plan = Plan::factory()->create(['max_rooms' => 50]);
        $this->hotel = Hotel::factory()->create(['plan_id' => $plan->id]);
        $this->branch = Branch::factory()->for($this->hotel)->create(['name' => 'Main']);
        app(HotelDefaults::class)->apply($this->hotel);
        $this->admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();
    }

    private function state(string $key): string
    {
        return collect($this->getJson('/api/v1/setup')->json('data.steps'))->firstWhere('key', $key)['state'];
    }

    public function test_new_hotel_starts_with_setup_to_do(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/setup')->assertOk()
            ->assertJsonPath('data.completed', false)
            ->assertJsonPath('data.started', false)
            ->assertJsonCount(12, 'data.steps')
            ->assertJsonPath('data.steps.0', ['key' => 'account', 'required' => true, 'state' => 'done']);
        $this->getJson('/api/v1/me')->assertJsonPath('hotel.setup.completed', false);
    }

    public function test_setup_page_opens_before_the_temporary_password_is_changed(): void
    {
        $admin = User::factory()->withRole($this->hotel, 'hotel_admin')->mustChangePassword()->create();
        $this->actingAs($admin);

        $this->assertSame('todo', $this->state('account'));
        $this->putJson('/api/v1/setup/steps/profile', ['state' => 'done'])->assertForbidden(); // password first
    }

    public function test_required_steps_cannot_be_skipped_and_rooms_must_exist(): void
    {
        $this->actingAs($this->admin);

        $this->putJson('/api/v1/setup/steps/profile', ['state' => 'skipped'])->assertJsonValidationErrors('step');
        $this->putJson('/api/v1/setup/steps/rooms', ['state' => 'done'])->assertJsonValidationErrors('step');
        $this->putJson('/api/v1/setup/steps/taxes', ['state' => 'skipped'])->assertOk();
        $this->putJson('/api/v1/setup/steps/nope', ['state' => 'done'])->assertJsonValidationErrors('step');
        $this->assertSame('skipped', $this->state('taxes'));
        $this->getJson('/api/v1/me')->assertJsonPath('hotel.setup.started', true)->assertJsonPath('hotel.setup.skipped', 1);
    }

    public function test_finish_needs_every_required_step(): void
    {
        $this->actingAs($this->admin);
        $this->postJson('/api/v1/setup/finish')->assertJsonValidationErrors('step');

        $this->putJson('/api/v1/setup/steps/profile', ['state' => 'done'])->assertOk();
        $this->putJson('/api/v1/setup/steps/branches', ['state' => 'done'])->assertOk();
        $type = $this->postJson('/api/v1/settings/room-types', ['branch_id' => $this->branch->id, 'name' => 'Standard', 'base_capacity' => 2, 'max_capacity' => 2, 'nightly_price' => '2000', 'currency' => 'ETB'])->json('data.id');
        $this->assertSame('done', $this->state('room_types'));
        $this->postJson('/api/v1/settings/rooms', ['room_type_id' => $type, 'number' => '101'])->assertCreated();
        $this->putJson('/api/v1/setup/steps/taxes', ['state' => 'done'])->assertOk();

        $this->postJson('/api/v1/setup/finish')->assertOk()->assertJsonPath('data.completed', true)->assertJsonPath('data.remaining', 0);
        $this->assertSame('done', $this->state('taxes'));
        $this->assertSame('skipped', $this->state('team')); // untouched optional steps keep the defaults
        $this->getJson('/api/v1/me')->assertJsonPath('hotel.setup.completed', true);
    }

    public function test_only_the_hotel_admin_runs_setup(): void
    {
        $manager = User::factory()->withRole($this->hotel, 'branch_manager')->create();
        $this->actingAs($manager)->getJson('/api/v1/setup')->assertForbidden();
        $this->getJson('/api/v1/me')->assertJsonPath('hotel.setup', null);
    }

    public function test_super_admin_sees_setup_progress(): void
    {
        $sa = User::factory()->superAdmin()->create();
        $this->actingAs($sa)->getJson("/api/v1/admin/hotels/{$this->hotel->id}")->assertOk()
            ->assertJsonPath('setup.completed', false)
            ->assertJsonPath('setup.steps.0.state', 'done');
    }
}
