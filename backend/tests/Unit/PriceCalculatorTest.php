<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\GuestType;
use App\Models\Hotel;
use App\Models\RateRule;
use App\Models\RoomType;
use App\Services\PriceCalculator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PriceCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private RoomType $standard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hotel = Hotel::factory()->create();
        app(TenantContext::class)->set($this->hotel);
        $branch = Branch::factory()->for($this->hotel)->create();
        $this->standard = RoomType::create(['branch_id' => $branch->id, 'name' => 'Standard', 'nightly_price' => '2000.00', 'currency' => 'ETB']);
    }

    private function rule(array $attrs): RateRule
    {
        return RateRule::create(array_merge(['name' => 'Rule', 'rule_type' => 'season', 'adjustment' => 'percent', 'value' => '0', 'priority' => 10, 'is_active' => true], $attrs));
    }

    private function price(string $date, ?int $guestType = null): string
    {
        return (new PriceCalculator)->night($this->standard, Carbon::parse($date), $guestType)['amount'];
    }

    public function test_base_price_when_no_rule_matches(): void
    {
        $this->assertSame('2000.00', $this->price('2026-11-11'));
    }

    public function test_weekend_rule_on_chosen_days_only(): void
    {
        $this->rule(['name' => 'Weekend', 'rule_type' => 'weekend', 'days_of_week' => [5, 6], 'adjustment' => 'percent', 'value' => '20']);

        $this->assertSame('2400.00', $this->price('2026-11-13')); // Friday
        $this->assertSame('2000.00', $this->price('2026-11-11')); // Wednesday
    }

    public function test_season_with_dates_and_fixed_price(): void
    {
        $this->rule(['name' => 'Timket', 'start_date' => '2027-01-18', 'end_date' => '2027-01-20', 'adjustment' => 'fixed_price', 'value' => '3500']);

        $this->assertSame('3500.00', $this->price('2027-01-19'));
        $this->assertSame('2000.00', $this->price('2027-01-21'));
    }

    public function test_highest_priority_wins_when_rules_overlap(): void
    {
        $this->rule(['name' => 'Weekend', 'rule_type' => 'weekend', 'days_of_week' => [6], 'adjustment' => 'amount', 'value' => '300', 'priority' => 10]);
        $this->rule(['name' => 'Holiday', 'start_date' => '2026-11-14', 'end_date' => '2026-11-14', 'adjustment' => 'fixed_price', 'value' => '5000', 'priority' => 50]);

        $this->assertSame('5000.00', $this->price('2026-11-14')); // Saturday + holiday
        $this->assertSame('2300.00', $this->price('2026-11-21')); // just Saturday
    }

    public function test_guest_type_discount_only_for_that_guest_type(): void
    {
        $corporate = GuestType::create(['name' => 'Corporate']);
        $this->rule(['name' => 'Corporate', 'rule_type' => 'guest_type', 'guest_type_id' => $corporate->id, 'adjustment' => 'percent', 'value' => '-15']);

        $this->assertSame('1700.00', $this->price('2026-11-11', $corporate->id));
        $this->assertSame('2000.00', $this->price('2026-11-11'));
    }

    public function test_rule_for_another_room_type_is_ignored_and_price_never_negative(): void
    {
        $other = RoomType::create(['branch_id' => $this->standard->branch_id, 'name' => 'Suite', 'nightly_price' => '6000', 'currency' => 'ETB']);
        $this->rule(['room_type_id' => $other->id, 'adjustment' => 'fixed_price', 'value' => '1']);
        $this->rule(['room_type_id' => $this->standard->id, 'adjustment' => 'amount', 'value' => '-9999', 'priority' => 5]);

        $this->assertSame('0.00', $this->price('2026-11-11'));
    }

    public function test_a_stay_is_priced_night_by_night(): void
    {
        $this->rule(['name' => 'Weekend', 'rule_type' => 'weekend', 'days_of_week' => [6, 7], 'adjustment' => 'percent', 'value' => '10']);

        $stay = (new PriceCalculator)->stay($this->standard, Carbon::parse('2026-11-13'), Carbon::parse('2026-11-16'));

        // Fri 2000 + Sat 2200 + Sun 2200; Monday (check-out) not charged.
        $this->assertSame('6400.00', $stay['total']);
        $this->assertCount(3, $stay['nights']);
    }
}
