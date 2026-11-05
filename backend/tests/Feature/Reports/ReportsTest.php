<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\HotelDefaults;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Every report number is checked against known data (build-plan.md risk 4). */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private Branch $branch;

    private User $admin;

    private int $cash;

    private int $reservationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2026-11-12 09:00');
        $this->hotel = Hotel::factory()->create(['plan_id' => Plan::factory()->create(['max_rooms' => null, 'modules' => []])->id, 'default_currency' => 'ETB']);
        $this->branch = Branch::factory()->for($this->hotel)->create(['name' => 'Bole', 'check_in_time' => '14:00', 'check_out_time' => '12:00']);
        app(HotelDefaults::class)->apply($this->hotel);
        $this->admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();

        [$type, $guest, $room] = $this->inHotel(function () {
            $type = RoomType::create(['branch_id' => $this->branch->id, 'name' => 'Standard', 'base_capacity' => 2, 'max_capacity' => 2, 'nightly_price' => '2000', 'currency' => 'ETB']);
            $room = Room::create(['branch_id' => $this->branch->id, 'room_type_id' => $type->id, 'number' => '101', 'status' => 'available']);
            Room::create(['branch_id' => $this->branch->id, 'room_type_id' => $type->id, 'number' => '102', 'status' => 'available']);
            $this->cash = PaymentMethod::where('name', 'Cash')->value('id');

            return [$type, Guest::create(['first_name' => 'Abebe', 'nationality' => 'Ethiopian']), $room];
        });

        // One stay 12 → 14 Nov with laundry, fully paid; one booking cancelled late.
        $this->actingAs($this->admin);
        $book = fn (string $in, string $out) => $this->postJson('/api/v1/reservations', ['branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => $in, 'check_out' => $out, 'guest_id' => $guest->id, 'source' => 'phone', 'rooms' => [['room_type_id' => $type->id]]])->json('data.id');
        $this->reservationId = $book('2026-11-12', '2026-11-14');
        $cancelled = $book('2026-11-13', '2026-11-14');
        $this->postJson("/api/v1/reservations/$cancelled/cancel", ['reason' => 'Changed plans'])->assertOk(); // inside 48 h: 1 night fee

        $this->at('2026-11-12 15:00');
        $stay = $this->getJson("/api/v1/reservations/{$this->reservationId}")->json('data.rooms.0.id');
        $this->postJson("/api/v1/reservations/{$this->reservationId}/check-in", ['rooms' => [['id' => $stay, 'room_id' => $room->id]], 'payment' => ['payment_method_id' => $this->cash, 'amount' => '1380']])->assertOk();
        $invoice = $this->getJson("/api/v1/reservations/{$this->reservationId}")->json('data.invoices.0.id');
        $this->postJson("/api/v1/invoices/$invoice/items", ['type' => 'extra', 'description' => 'Laundry', 'quantity' => '2', 'unit_price' => '150'])->assertCreated();
        $this->at('2026-11-14 11:00');
        $this->postJson("/api/v1/reservations/{$this->reservationId}/check-out", ['payment' => ['payment_method_id' => $this->cash, 'amount' => '3565']])->assertOk();
    }

    private function at(string $time): void
    {
        $this->travelTo(Carbon::parse($time, 'Africa/Addis_Ababa'));
    }

    private function inHotel(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->hotel, $fn);
    }

    public function test_kpis_match_the_stay(): void
    {
        $k = $this->getJson('/api/v1/reports/overview?from=2026-11-12&to=2026-11-14')->assertOk()->json('data.kpis');

        $this->assertSame(6, $k['available_nights']); // 2 rooms × 3 days
        $this->assertSame(2, $k['room_nights']);
        $this->assertEquals(33.3, $k['occupancy']);
        $this->assertSame('4000.00', $k['room_revenue']);
        $this->assertSame('2000.00', $k['adr']);
        $this->assertSame('666.67', $k['revpar']);
        $this->assertSame('300.00', $k['extras']);
        $this->assertSame('2000.00', $k['cancellation_fees']);
        $this->assertSame('6300.00', $k['revenue']); // 4000 rooms + 300 laundry + 2000 cancellation fee
        $this->assertSame('645.00', $k['tax']);
        $this->assertSame('4945.00', $k['payments']);
        $this->assertSame(2, $k['bookings']);
        $this->assertEquals(50, $k['cancellation_rate']);
        $this->assertEquals(2, $k['average_stay']);
    }

    public function test_daily_series_room_types_and_compare(): void
    {
        $res = $this->getJson('/api/v1/reports/performance?from=2026-11-12&to=2026-11-14&compare=prev')->assertOk();
        $daily = collect($res->json('data.daily'))->keyBy('date');
        $this->assertSame(1, $daily['2026-11-12']['room_nights']);
        $this->assertEquals(50, $daily['2026-11-13']['occupancy']);
        $this->assertSame(0, $daily['2026-11-14']['room_nights']);
        $this->assertSame('1380.00', $daily['2026-11-12']['payments']);
        $this->assertSame('3565.00', $daily['2026-11-14']['payments']);
        $res->assertJsonPath('data.room_types.0.room_nights', 2)->assertJsonPath('data.compare_range', ['2026-11-09', '2026-11-11'])->assertJsonPath('data.compare.room_nights', 0);
    }

    public function test_money_reports(): void
    {
        $this->getJson('/api/v1/reports/payments?from=2026-11-01&to=2026-11-30')->assertJsonPath('data.total', '4945.00')->assertJsonPath('data.by_method.0.method', 'Cash');
        $tax = $this->getJson('/api/v1/reports/tax?from=2026-11-01&to=2026-11-30')->json('data.rows.0');
        $this->assertSame(['VAT', '4300.00', '645.00'], [$tax['name'], $tax['base'], $tax['tax']]);
        $this->getJson('/api/v1/reports/outstanding')->assertJsonCount(0, 'data.rows');
        $this->getJson('/api/v1/reports/cancellations?from=2026-11-01&to=2026-11-30')->assertJsonPath('data.cancelled', 1)->assertJsonPath('data.fees', '2000.00');
        $this->getJson('/api/v1/reports/guests?from=2026-11-01&to=2026-11-30')->assertJsonPath('data.nationalities.0.nationality', 'Ethiopian')->assertJsonPath('data.top.0.spent', '4945.00');
    }

    public function test_drilldown_shows_the_bookings_behind_a_number(): void
    {
        $this->getJson('/api/v1/reports/drilldown?metric=room_nights&date=2026-11-13')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.room', '101');
        $this->getJson('/api/v1/reports/drilldown?metric=payments&date=2026-11-14')->assertJsonPath('data.0.amount', '3565.00');
    }

    public function test_export_to_csv_and_excel(): void
    {
        $csv = $this->get('/api/v1/reports/performance?from=2026-11-12&to=2026-11-14&format=csv')->assertOk()->getContent();
        $this->assertStringContainsString('2026-11-13', (string) $csv);
        $this->assertStringStartsWith('PK', $this->get('/api/v1/reports/tax?from=2026-11-01&to=2026-11-30&format=xlsx')->assertOk()->getContent());
    }

    public function test_dashboard_today(): void
    {
        $this->at('2026-11-14 11:30');
        $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.departed', 1)->assertJsonPath('data.in_house', 0)
            ->assertJsonPath('data.payments_today', '3565.00')->assertJsonPath('data.unpaid_balances', '0.00');
    }

    public function test_permissions_and_hotel_isolation(): void
    {
        $receptionist = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $receptionist->branches()->attach($this->branch->id);
        $this->actingAs($receptionist)->getJson('/api/v1/reports/overview')->assertForbidden();
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonMissingPath('data.revenue_today'); // no money figures

        $accountant = User::factory()->withRole($this->hotel, 'accountant')->create();
        $accountant->branches()->attach($this->branch->id);
        $this->actingAs($accountant)->getJson('/api/v1/reports/tax?from=2026-11-01&to=2026-11-30')->assertOk();
        $this->getJson('/api/v1/reports/guests')->assertForbidden(); // no guest details for accountants

        $other = Hotel::factory()->create(['plan_id' => $this->hotel->plan_id]);
        Branch::factory()->for($other)->create();
        $stranger = User::factory()->withRole($other, 'hotel_admin')->create();
        $this->actingAs($stranger)->getJson('/api/v1/reports/overview?from=2026-11-12&to=2026-11-14')->assertJsonPath('data.kpis.room_nights', 0)->assertJsonPath('data.kpis.payments', '0.00');
    }
}
