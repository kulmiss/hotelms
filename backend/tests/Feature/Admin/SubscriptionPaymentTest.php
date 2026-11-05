<?php

namespace Tests\Feature\Admin;

use App\Models\Hotel;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SubscriptionPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->owner = User::factory()->superAdmin()->create();
        $this->plan = Plan::factory()->create(['price_per_room' => '100.00', 'minimum_monthly' => '2000.00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pay(Hotel $hotel, array $overrides = [])
    {
        return $this->actingAs($this->owner)->postJson("/api/v1/admin/hotels/{$hotel->id}/payments", [
            'plan_id' => $this->plan->id,
            'amount' => '2000.00',
            'currency' => 'ETB',
            'method' => 'bank_transfer',
            'reference' => 'CBE-123',
            'billing_cycle' => 'monthly',
            'periods' => 1,
            ...$overrides,
        ]);
    }

    public function test_payment_reactivates_a_suspended_hotel_immediately(): void
    {
        $hotel = Hotel::factory()->create(['status' => 'suspended', 'subscription_ends_at' => '2026-10-01', 'trial_ends_at' => null]);

        $this->pay($hotel)->assertCreated()
            ->assertJsonPath('hotel.status', 'active')
            ->assertJsonPath('hotel.subscription_ends_at', '2026-12-09');
    }

    public function test_paying_early_adds_to_the_end_of_the_current_period(): void
    {
        $hotel = Hotel::factory()->create(['subscription_ends_at' => '2026-11-30']);

        $this->pay($hotel, ['billing_cycle' => 'yearly', 'amount' => '20000.00'])->assertCreated()
            ->assertJsonPath('hotel.subscription_ends_at', '2027-11-30')
            ->assertJsonPath('data.months', 12);
    }

    public function test_quote_uses_the_plan_minimum_until_rooms_exist(): void
    {
        $hotel = Hotel::factory()->create();

        $this->actingAs($this->owner)
            ->getJson("/api/v1/admin/hotels/{$hotel->id}/payments/quote?plan_id={$this->plan->id}&billing_cycle=yearly&periods=1")
            ->assertOk()
            ->assertJsonPath('amount', '20000.00') // 2000 minimum × 10 months
            ->assertJsonPath('months', 12);
    }

    public function test_amount_must_be_positive(): void
    {
        $hotel = Hotel::factory()->create();

        $this->pay($hotel, ['amount' => '0'])->assertUnprocessable()->assertJsonValidationErrors('amount');
    }
}
