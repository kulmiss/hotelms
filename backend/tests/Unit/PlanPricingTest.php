<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

class PlanPricingTest extends TestCase
{
    private function plan(): Plan
    {
        return new Plan(['price_per_room' => '150.00', 'minimum_monthly' => '3000.00', 'yearly_free_months' => 2]);
    }

    public function test_small_hotels_pay_the_minimum(): void
    {
        $this->assertSame('3000.00', $this->plan()->monthlyPrice(10)); // 10 × 150 = 1500 < 3000
    }

    public function test_bigger_hotels_pay_per_room(): void
    {
        $this->assertSame('6000.00', $this->plan()->monthlyPrice(40)); // 40 × 150
    }

    public function test_yearly_billing_gives_two_months_free(): void
    {
        $this->assertSame('60000.00', $this->plan()->priceFor(40, 'yearly')); // 6000 × 10
        $this->assertSame('18000.00', $this->plan()->priceFor(40, 'monthly', 3));
    }

    public function test_money_rounds_half_up_without_floats(): void
    {
        $this->assertSame('0.30', Money::add('0.10', '0.20'));
        $this->assertSame('10.01', Money::mul('3.335', '3'));
        $this->assertSame('-1.01', Money::round('-1.005'));
    }
}
