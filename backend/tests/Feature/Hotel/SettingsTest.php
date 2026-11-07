<?php

namespace Tests\Feature\Hotel;

use App\Models\Branch;
use App\Models\CancellationPolicy;
use App\Models\Hotel;
use App\Models\Plan;
use App\Models\Tax;
use App\Models\User;
use App\Services\HotelDefaults;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $plan = Plan::factory()->create(['max_branches' => 2, 'modules' => ['housekeeping', 'cashier_close']]);
        $this->hotel = Hotel::factory()->create(['plan_id' => $plan->id]);
        Branch::factory()->for($this->hotel)->create(['name' => 'Main']);
        app(HotelDefaults::class)->apply($this->hotel);
        $this->admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();
    }

    public function test_new_hotels_start_with_sensible_defaults(): void
    {
        $this->actingAs($this->admin);

        $this->getJson('/api/v1/settings/currencies')->assertOk()->assertJsonPath('main', 'ETB');
        $this->getJson('/api/v1/settings/payment-methods')->assertJsonCount(4, 'data');
        $this->getJson('/api/v1/settings/taxes')->assertJsonFragment(['name' => 'VAT', 'rate' => '15.00']);
        $this->getJson('/api/v1/settings/cancellation-policies')->assertJsonCount(4, 'data')
            ->assertJsonFragment(['name' => 'Standard', 'is_default' => true]);
        $this->getJson('/api/v1/settings/booking-rules')->assertJsonPath('data.pending_expiry_hours', 24);
        // A percent fee is at most one full night (100%); a fixed fee can be any amount (owner, 2026-11-08).
        $rules = $this->getJson('/api/v1/settings/booking-rules')->json('data');
        $this->putJson('/api/v1/settings/booking-rules', ['late_check_out_fee_type' => 'percent', 'late_check_out_fee_value' => '150'] + $rules)
            ->assertJsonPath('errors.late_check_out_fee_value.0', 'A percent fee can be at most 100 (one full night).');
        $this->putJson('/api/v1/settings/booking-rules', ['late_check_out_fee_type' => 'fixed', 'late_check_out_fee_value' => '1500'] + $rules)->assertOk();
        $this->getJson('/api/v1/me')->assertJsonPath('hotel.modules', ['housekeeping', 'cashier_close']);
    }

    public function test_hotel_profile_and_invoice_settings(): void
    {
        $this->actingAs($this->admin)->putJson('/api/v1/settings/hotel', [
            'name' => 'Sheger Hotel', 'legal_name' => 'Sheger Hospitality PLC', 'tin' => '0012345678',
            'vat_number' => 'VAT-1', 'invoice_prefix' => 'shg', 'invoice_footer' => 'Thank you',
        ])->assertOk()
            ->assertJsonPath('data.legal_name', 'Sheger Hospitality PLC')
            ->assertJsonPath('data.invoice_prefix', 'SHG');
    }

    public function test_branch_limit_comes_from_the_plan(): void
    {
        $this->actingAs($this->admin);
        $branch = ['name' => 'Piassa', 'check_in_time' => '14:00', 'check_out_time' => '12:00'];

        $this->postJson('/api/v1/settings/branches', $branch)->assertCreated();
        $this->postJson('/api/v1/settings/branches', ['name' => 'Third'] + $branch)
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_extra_currency_with_a_hand_entered_rate(): void
    {
        $this->actingAs($this->admin);

        $this->postJson('/api/v1/settings/currencies', ['code' => 'USD', 'symbol' => '$'])->assertCreated();
        $this->postJson('/api/v1/settings/exchange-rates', ['from_currency' => 'USD', 'rate' => '128.5'])->assertCreated();

        $this->getJson('/api/v1/settings/currencies')->assertJsonFragment(['code' => 'USD', 'current_rate' => '128.500000']);
        $this->postJson('/api/v1/settings/exchange-rates', ['from_currency' => 'ETB', 'rate' => '1'])->assertUnprocessable();
    }

    public function test_main_currency_cannot_be_removed(): void
    {
        $this->actingAs($this->admin);
        $etb = collect($this->getJson('/api/v1/settings/currencies')->json('data'))->firstWhere('code', 'ETB');

        $this->deleteJson("/api/v1/settings/currencies/{$etb['id']}")->assertUnprocessable();
    }

    public function test_only_one_default_cancellation_policy(): void
    {
        $this->actingAs($this->admin);
        $strict = collect($this->getJson('/api/v1/settings/cancellation-policies')->json('data'))->firstWhere('name', 'Strict');

        $this->putJson("/api/v1/settings/cancellation-policies/{$strict['id']}", array_merge($strict, ['is_default' => true]))->assertOk();

        $defaults = app(TenantContext::class)->runAs($this->hotel, fn () => CancellationPolicy::where('is_default', true)->pluck('name')->all());
        $this->assertSame(['Strict'], $defaults);
    }

    public function test_modules_outside_the_plan_cannot_be_switched_on(): void
    {
        $this->actingAs($this->admin);

        $this->putJson('/api/v1/settings/modules', ['modules' => ['housekeeping' => false]])->assertOk();
        $this->getJson('/api/v1/me')->assertJsonPath('hotel.modules', ['cashier_close']);

        $this->putJson('/api/v1/settings/modules', ['modules' => ['attendance' => true]])
            ->assertUnprocessable()->assertJsonPath('code', 'module_not_in_plan');
    }

    public function test_receptionists_cannot_change_settings(): void
    {
        $rc = User::factory()->withRole($this->hotel, 'receptionist')->create();

        $this->actingAs($rc)->getJson('/api/v1/settings/taxes')->assertForbidden();
        $this->putJson('/api/v1/settings/booking-rules', [])->assertForbidden();
    }

    public function test_settings_of_another_hotel_cannot_be_reached(): void
    {
        $other = Hotel::factory()->create();
        app(HotelDefaults::class)->apply($other);
        $foreignTax = app(TenantContext::class)->runAs($other, fn () => Tax::first());

        $this->actingAs($this->admin)
            ->putJson("/api/v1/settings/taxes/{$foreignTax->id}", ['name' => 'Hack', 'rate' => '1', 'applies_to' => 'all'])
            ->assertNotFound();
    }
}
