<?php

namespace Tests\Feature\Hotel;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\Holiday;
use App\Models\Hotel;
use App\Models\Plan;
use App\Models\RateRule;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\HotelDefaults;
use App\Services\Pricing\EthiopianHolidays;
use App\Services\Switches;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Seasons and pricing (owner, 14 Nov 2026; workflows.md Q28): stay rules, holidays, minimum stay, prices set by hand. */
class SmartPricingTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private Branch $branch;

    private User $admin;

    private User $manager;

    private User $clerk;

    private RoomType $type;

    private Guest $guest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-14 09:00', 'Africa/Addis_Ababa'));
        $this->hotel = Hotel::factory()->create(['plan_id' => Plan::where('code', 'starter')->value('id'), 'status' => 'active', 'default_currency' => 'ETB']);
        app(HotelDefaults::class)->apply($this->hotel);
        $this->branch = Branch::factory()->for($this->hotel)->create();
        $this->admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();
        $this->manager = User::factory()->withRole($this->hotel, 'branch_manager')->create();
        $this->manager->branches()->attach($this->branch->id);
        $this->clerk = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $this->clerk->branches()->attach($this->branch->id);
        $this->inHotel(function () {
            $this->type = RoomType::create(['branch_id' => $this->branch->id, 'name' => 'Standard', 'base_capacity' => 2, 'max_capacity' => 2, 'nightly_price' => '2000', 'currency' => 'ETB']);
            foreach (['101', '102', '103'] as $n) {
                Room::create(['branch_id' => $this->branch->id, 'room_type_id' => $this->type->id, 'number' => $n, 'status' => 'available']);
            }
            $this->guest = Guest::create(['first_name' => 'Abebe', 'last_name' => 'Kebede', 'phone' => '+251 911 223344']);
        });
    }

    private function inHotel(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->hotel, $fn);
    }

    private function rule(array $data): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/settings/rate-rules', $data + ['room_type_id' => null, 'priority' => 10, 'is_active' => true])->assertCreated();
    }

    /** @return array<string, mixed> the quote's first room */
    private function quote(string $in, string $out, array $room = [], array $extra = [], ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->admin)->postJson('/api/v1/reservations/quote', $extra + [
            'branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => $in, 'check_out' => $out,
            'guest_id' => $this->guest->id, 'rooms' => [$room + ['room_type_id' => $this->type->id, 'adults' => 1]],
        ])->assertOk()->json('data.rooms.0');
    }

    public function test_long_stay_early_bird_and_last_minute(): void
    {
        $this->rule(['name' => 'Long stay', 'rule_type' => 'long_stay', 'min_nights' => 7, 'adjustment' => 'percent', 'value' => '-10']);
        $this->assertSame('12000.00', $this->quote('2026-11-20', '2026-11-26')['total']); // 6 nights: no discount
        $week = $this->quote('2026-11-20', '2026-11-27');
        $this->assertSame('12600.00', $week['total']); // 7 × 1800
        $this->assertSame('Long stay', $week['lines'][0]['rule_name']);

        $this->rule(['name' => 'Early bird', 'rule_type' => 'early_bird', 'days_before' => 30, 'adjustment' => 'amount', 'value' => '-300', 'priority' => 20]);
        $this->assertSame('1700.00', $this->quote('2026-12-20', '2026-12-21')['lines'][0]['amount']); // 36 days ahead
        $this->assertSame('2000.00', $this->quote('2026-11-20', '2026-11-21')['lines'][0]['amount']); // 6 days ahead

        $this->rule(['name' => 'Last minute', 'rule_type' => 'last_minute', 'days_before' => 1, 'adjustment' => 'percent', 'value' => '-20', 'priority' => 30]);
        $this->assertSame('1600.00', $this->quote('2026-11-15', '2026-11-16')['lines'][0]['amount']); // tomorrow

        // On top of the night's own rule: weekend +500, then -20 % last minute → "Weekend · Last minute".
        $this->rule(['name' => 'Weekend', 'rule_type' => 'weekend', 'days_of_week' => [6], 'adjustment' => 'amount', 'value' => '500']);
        $line = $this->quote('2026-11-14', '2026-11-15')['lines'][0]; // Saturday, today
        $this->assertSame(['2000.00', 'Weekend · Last minute'], [$line['amount'], $line['rule_name']]);

        // A whole-stay rule never sets one fixed price.
        $this->postJson('/api/v1/settings/rate-rules', ['name' => 'X', 'rule_type' => 'long_stay', 'min_nights' => 5, 'adjustment' => 'fixed_price', 'value' => '1000', 'priority' => 1])->assertJsonValidationErrors('adjustment');
    }

    public function test_an_edit_keeps_the_early_bird_price_of_the_day_it_was_booked(): void
    {
        $this->rule(['name' => 'Early bird', 'rule_type' => 'early_bird', 'days_before' => 30, 'adjustment' => 'percent', 'value' => '-10']);
        $id = $this->actingAs($this->admin)->postJson('/api/v1/reservations', ['branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2026-12-20', 'check_out' => '2026-12-22', 'guest_id' => $this->guest->id, 'rooms' => [['room_type_id' => $this->type->id]]])->assertCreated()->json('data.id');
        $this->travelTo(Carbon::parse('2026-12-10 09:00', 'Africa/Addis_Ababa')); // now only 10 days before
        $this->putJson("/api/v1/reservations/{$id}", ['stay_type' => 'nightly', 'check_in' => '2026-12-20', 'check_out' => '2026-12-23', 'guest_id' => $this->guest->id, 'rooms' => [['room_type_id' => $this->type->id]]])->assertOk();
        $this->assertSame('5400.00', $this->getJson("/api/v1/reservations/{$id}")->json('data.rooms.0.total'));
    }

    public function test_holidays_preset_and_holiday_prices(): void
    {
        $r = $this->actingAs($this->admin)->postJson('/api/v1/settings/holidays/ethiopian')->assertOk();
        $this->assertGreaterThan(30, $r->json('added')); // 3 years
        $this->assertSame(0, $this->postJson('/api/v1/settings/holidays/ethiopian')->json('added')); // only once
        $dates = collect($r->json('data'))->pluck('date')->map(fn ($d) => substr($d, 0, 10));
        foreach (['2027-01-07', '2027-01-19', '2027-04-30', '2027-05-02', '2027-09-12', '2027-09-28', '2028-04-16', '2029-04-08'] as $d) {
            $this->assertContains($d, $dates, $d);
        }
        $this->assertSame('expected', collect($r->json('data'))->firstWhere('name', 'Eid al-Fitr')['kind']);

        $this->rule(['name' => 'Holiday', 'rule_type' => 'holiday', 'adjustment' => 'percent', 'value' => '25', 'min_stay' => 2]);
        $lines = $this->quote('2027-01-06', '2027-01-09', [], ['allow_short_stay' => true])['lines'];
        $this->assertSame(['2000.00', '2500.00', '2000.00'], array_column($lines, 'amount')); // Genna on 7 Jan

        // A holiday the hotel turned off is a normal night; editing the list works.
        $genna = collect($this->getJson('/api/v1/settings/holidays')->json('data'))->first(fn ($h) => str_starts_with($h['date'], '2027-01-07'));
        $this->putJson("/api/v1/settings/holidays/{$genna['id']}", ['name' => $genna['name'], 'date' => '2027-01-07', 'is_active' => false])->assertOk();
        $this->assertSame('2000.00', $this->quote('2027-01-07', '2027-01-09')['lines'][0]['amount']);
    }

    public function test_fasika_is_calculated(): void
    {
        foreach ([2026 => '2026-04-12', 2027 => '2027-05-02', 2028 => '2028-04-16', 2029 => '2029-04-08', 2030 => '2030-04-28', 2024 => '2024-05-05'] as $y => $d) {
            $this->assertSame($d, EthiopianHolidays::fasika($y)->toDateString());
        }
        $year = collect(EthiopianHolidays::year(2027))->keyBy('key');
        $this->assertSame(['2027-03-02', '2027-05-05', '2027-05-28', '2027-09-12', '2027-09-28', '2027-04-30'], [$year['adwa']['date'], $year['patriots']['date'], $year['derg']['date'], $year['enkutatash']['date'], $year['meskel']['date'], $year['siklet']['date']]);
        $this->assertSame('ገና', EthiopianHolidays::upcoming('am', Carbon::parse('2026-11-14'))[0]['name']);
    }

    public function test_minimum_stay_in_a_season(): void
    {
        $this->rule(['name' => 'Timket season', 'rule_type' => 'season', 'start_date' => '2027-01-17', 'end_date' => '2027-01-21', 'adjustment' => 'percent', 'value' => '20', 'min_stay' => 3]);
        $body = ['branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2027-01-18', 'check_out' => '2027-01-20', 'guest_id' => $this->guest->id, 'rooms' => [['room_type_id' => $this->type->id]]];
        $this->actingAs($this->clerk)->postJson('/api/v1/reservations', $body)->assertJsonValidationErrors(['rooms.0.room_type_id' => 'at least 3 nights']);
        $this->actingAs($this->clerk)->postJson('/api/v1/reservations', $body + ['allow_short_stay' => true])->assertJsonValidationErrors('rooms.0.room_type_id'); // no permission
        $this->actingAs($this->clerk)->postJson('/api/v1/reservations', ['check_out' => '2027-01-21'] + $body)->assertCreated();

        $this->actingAs($this->manager)->postJson('/api/v1/reservations', $body + ['allow_short_stay' => true])->assertCreated();
        $this->assertTrue(ActivityLog::withoutGlobalScopes()->where('event', 'reservation.short_stay')->where('user_id', $this->manager->id)->exists());
    }

    public function test_price_set_by_hand_needs_the_permission_a_reason_and_is_logged(): void
    {
        $body = ['branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2026-11-20', 'check_out' => '2026-11-22', 'guest_id' => $this->guest->id, 'rooms' => [['room_type_id' => $this->type->id, 'price_override' => '1500']]];
        $this->actingAs($this->clerk)->postJson('/api/v1/reservations', $body + ['price_override_reason' => 'Friend'])->assertJsonValidationErrors('rooms.0.price_override');
        $this->actingAs($this->manager)->postJson('/api/v1/reservations', $body)->assertJsonValidationErrors('price_override_reason');
        $id = $this->actingAs($this->manager)->postJson('/api/v1/reservations', $body + ['price_override_reason' => 'Regular guest'])->assertCreated()->json('data.id');

        $room = $this->getJson("/api/v1/reservations/{$id}")->json('data.rooms.0');
        $this->assertSame(['3000.00', '1500.00', 'Price set by hand'], [$room['total'], $room['price_override'], $room['lines'][0]['rule_name']]);
        $log = ActivityLog::withoutGlobalScopes()->where('event', 'reservation.price_override')->firstOrFail();
        $this->assertSame(['Regular guest', '4000.00', $this->manager->id], [$log->properties['reason'], $log->properties['calculated_total'], $log->user_id]);

        // Staff without the permission can still edit the booking when they keep that price.
        $this->actingAs($this->clerk)->putJson("/api/v1/reservations/{$id}", ['notes' => 'Late'] + $body)->assertOk();
        $this->assertSame(1, ActivityLog::withoutGlobalScopes()->where('event', 'reservation.price_override')->count());
        $this->actingAs($this->clerk)->putJson("/api/v1/reservations/{$id}", array_replace_recursive($body, ['rooms' => [['price_override' => '1000']]]))->assertJsonValidationErrors('rooms.0.price_override');
    }

    public function test_free_plan_has_weekend_season_and_holiday_prices_only(): void
    {
        $this->hotel->update(['plan_id' => Plan::where('code', 'free')->value('id')]);
        app(Switches::class)->flush();
        $this->actingAs($this->admin)->postJson('/api/v1/settings/rate-rules', ['name' => 'Long', 'rule_type' => 'long_stay', 'min_nights' => 5, 'adjustment' => 'percent', 'value' => '-10', 'priority' => 1])->assertJsonValidationErrors('rule_type');
        $this->postJson('/api/v1/settings/rate-rules', ['name' => 'Season', 'rule_type' => 'season', 'start_date' => '2027-01-01', 'end_date' => '2027-01-31', 'adjustment' => 'percent', 'value' => '10', 'priority' => 1, 'min_stay' => 3])->assertJsonValidationErrors('min_stay');
        $this->postJson('/api/v1/settings/rate-rules', ['name' => 'Season', 'rule_type' => 'season', 'start_date' => '2027-01-01', 'end_date' => '2027-01-31', 'adjustment' => 'percent', 'value' => '10', 'priority' => 1])->assertCreated();

        // Rules made on a bigger plan stop counting after a downgrade (nothing is deleted).
        $this->inHotel(fn () => RateRule::create(['name' => 'Old long stay', 'rule_type' => 'long_stay', 'min_nights' => 2, 'adjustment' => 'percent', 'value' => '-50', 'priority' => 99, 'is_active' => true]));
        $this->assertSame('2200.00', $this->quote('2027-01-10', '2027-01-12')['lines'][0]['amount']);
    }

    public function test_holidays_stay_in_their_hotel(): void
    {
        $other = Hotel::factory()->create();
        app(TenantContext::class)->runAs($other, fn () => Holiday::create(['name' => 'Other', 'date' => '2026-12-01']));
        $this->rule(['name' => 'Holiday', 'rule_type' => 'holiday', 'adjustment' => 'fixed_price', 'value' => '9000']);
        $this->assertSame([], $this->actingAs($this->admin)->getJson('/api/v1/settings/holidays')->json('data'));
        $this->assertSame('2000.00', $this->quote('2026-12-01', '2026-12-02')['lines'][0]['amount']);
        $id = app(TenantContext::class)->runAs($other, fn () => Holiday::first()->id);
        $this->putJson("/api/v1/settings/holidays/{$id}", ['name' => 'Mine', 'date' => '2026-12-01'])->assertNotFound();
    }
}
