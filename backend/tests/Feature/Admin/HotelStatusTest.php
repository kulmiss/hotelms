<?php

namespace Tests\Feature\Admin;

use App\Models\Hotel;
use App\Services\HotelStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HotelStatusTest extends TestCase
{
    use RefreshDatabase;

    private function statusOn(string $date, array $attributes): string
    {
        $hotel = Hotel::factory()->make(['subscription_ends_at' => null, 'trial_ends_at' => null, ...$attributes]);

        return app(HotelStatus::class)->compute($hotel, Carbon::parse($date));
    }

    public function test_paid_hotels_are_active_until_the_last_paid_day(): void
    {
        $this->assertSame('active', $this->statusOn('2026-12-31', ['subscription_ends_at' => '2026-12-31']));
    }

    public function test_hotels_in_trial(): void
    {
        $this->assertSame('trial', $this->statusOn('2026-11-10', ['trial_ends_at' => '2026-11-30']));
    }

    public function test_three_days_grace_after_expiry_then_suspended(): void
    {
        $paid = ['subscription_ends_at' => '2026-11-30'];

        $this->assertSame('grace', $this->statusOn('2026-12-01', $paid));
        $this->assertSame('grace', $this->statusOn('2026-12-03', $paid));
        $this->assertSame('suspended', $this->statusOn('2026-12-04', $paid));
    }

    public function test_trial_end_also_gets_grace(): void
    {
        $trial = ['trial_ends_at' => '2026-11-30'];

        $this->assertSame('grace', $this->statusOn('2026-12-02', $trial));
        $this->assertSame('suspended', $this->statusOn('2026-12-10', $trial));
    }

    public function test_manual_suspension_wins(): void
    {
        $this->assertSame('suspended', $this->statusOn('2026-11-10', [
            'subscription_ends_at' => '2027-01-01',
            'suspended_at' => now(),
        ]));
    }

    public function test_the_hourly_command_updates_statuses(): void
    {
        $hotel = Hotel::factory()->create(['status' => 'active', 'subscription_ends_at' => now()->subDays(10), 'trial_ends_at' => null]);

        $this->artisan('hotels:update-statuses')->assertSuccessful();

        $this->assertSame('suspended', $hotel->fresh()->status);
    }
}
