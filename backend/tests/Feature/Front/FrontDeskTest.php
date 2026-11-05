<?php

namespace Tests\Feature\Front;

use App\Models\Branch;
use App\Models\ChargeType;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\HotelDefaults;
use App\Services\HotelSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FrontDeskTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private Branch $branch;

    private User $admin;

    private User $receptionist;

    private RoomType $standard;

    private RoomType $deluxe;

    private Guest $guest;

    private int $cash;

    /** @var array<string, int> room number => id */
    private array $rooms = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2026-11-12 09:00'); // Thursday

        $this->hotel = Hotel::factory()->create(['plan_id' => Plan::factory()->create(['max_rooms' => null, 'modules' => []])->id, 'default_currency' => 'ETB']);
        $this->branch = Branch::factory()->for($this->hotel)->create(['name' => 'Bole', 'code' => 'BL', 'check_in_time' => '14:00', 'check_out_time' => '12:00']);
        app(HotelDefaults::class)->apply($this->hotel);
        $this->admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();
        $this->receptionist = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $this->receptionist->branches()->attach($this->branch->id);

        $this->inHotel(function () {
            $this->standard = RoomType::create(['branch_id' => $this->branch->id, 'name' => 'Standard', 'base_capacity' => 2, 'max_capacity' => 2, 'nightly_price' => '2000', 'currency' => 'ETB']);
            $this->deluxe = RoomType::create(['branch_id' => $this->branch->id, 'name' => 'Deluxe', 'base_capacity' => 2, 'max_capacity' => 3, 'nightly_price' => '3000', 'hourly_price' => '300', 'currency' => 'ETB']);
            foreach (['101' => $this->standard, '102' => $this->standard, '201' => $this->deluxe] as $n => $type) {
                $this->rooms[$n] = Room::create(['branch_id' => $this->branch->id, 'room_type_id' => $type->id, 'number' => (string) $n, 'status' => 'available'])->id;
            }
            $this->guest = Guest::create(['first_name' => 'Abebe', 'last_name' => 'Kebede']);
            $this->cash = PaymentMethod::where('name', 'Cash')->value('id');
            ChargeType::create(['name' => 'Laundry', 'default_price' => '150', 'currency' => 'ETB', 'is_active' => true]);
        });
    }

    private function at(string $time): void
    {
        $this->travelTo(Carbon::parse($time, 'Africa/Addis_Ababa'));
    }

    private function inHotel(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->hotel, $fn);
    }

    /** A confirmed booking 12 → 14 Nov (deposit paid), made at 09:00. */
    private function booked(int $rooms = 1, string $in = '2026-11-12', string $out = '2026-11-14'): int
    {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/reservations', [
            'branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => $in, 'check_out' => $out, 'guest_id' => $this->guest->id,
            'rooms' => array_fill(0, $rooms, ['room_type_id' => $this->standard->id, 'adults' => 2]),
        ])->assertCreated()->json('data.id');
        $deposit = $this->getJson("/api/v1/reservations/$id")->json('data.deposit_required');
        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'deposit', 'payment_method_id' => $this->cash, 'amount' => $deposit])->assertJsonPath('data.status', 'confirmed');

        return $id;
    }

    private function checkIn(int $id, array $rooms, array $extra = []): TestResponse
    {
        $stays = $this->getJson("/api/v1/reservations/$id")->json('data.rooms');
        $payload = ['rooms' => array_map(fn ($stay, $n) => ['id' => $stay['id'], 'room_id' => $this->rooms[$n]], $stays, $rooms)] + $extra;

        return $this->postJson("/api/v1/reservations/$id/check-in", $payload);
    }

    private function invoice(int $reservationId): array
    {
        $invoiceId = $this->getJson("/api/v1/reservations/$reservationId")->json('data.invoices.0.id');

        return $this->getJson("/api/v1/invoices/$invoiceId")->json('data');
    }

    private function room(string $n): Room
    {
        return $this->inHotel(fn () => Room::find($this->rooms[$n]));
    }

    public function test_check_in_opens_the_bill_with_nights_tax_and_the_deposit(): void
    {
        $id = $this->booked();
        $this->at('2026-11-12 15:00');

        $this->checkIn($id, ['101'])->assertOk()->assertJsonPath('data.status', 'checked_in')->assertJsonPath('data.rooms.0.room_number', '101');
        $this->assertSame('occupied', $this->room('101')->status);

        $inv = $this->invoice($id);
        $this->assertCount(2, $inv['items']);
        $this->assertSame('4000.00', $inv['subtotal']);
        $this->assertSame('600.00', $inv['tax_total']);
        $this->assertSame('4600.00', $inv['total']);
        $this->assertSame('1380.00', $inv['paid_total']); // the deposit counts
        $this->assertSame('3220.00', $inv['balance']);
        $this->assertSame('partially_paid', $inv['status']);
        $this->assertStringStartsWith('INV-2026-', $inv['number']);
    }

    public function test_early_check_in_adds_a_fee_that_reception_can_waive(): void
    {
        $id = $this->booked();
        $this->at('2026-11-12 10:00');
        $this->actingAs($this->receptionist);
        $this->getJson("/api/v1/reservations/$id/check-in")->assertJsonPath('data.rooms.0.early_fee', '1000.00'); // 50% of a night

        $this->checkIn($id, ['101'])->assertOk();
        $items = collect($this->invoice($id)['items']);
        $this->assertSame('1000.00', $items->firstWhere('type', 'early_check_in')['amount']);

        $other = $this->actingAs($this->admin)->booked();
        $this->actingAs($this->receptionist);
        $this->checkIn($other, ['102'], ['waive_early_fee' => true])->assertOk();
        $fee = collect($this->invoice($other)['items'])->firstWhere('type', 'early_check_in');
        $this->assertTrue($fee['is_waived']);
        $this->assertSame('0.00', $fee['amount']);
    }

    public function test_check_in_refuses_wrong_rooms_dates_and_missing_deposit(): void
    {
        $id = $this->booked();
        $this->inHotel(fn () => Room::find($this->rooms['102'])->update(['status' => 'dirty']));
        $this->at('2026-11-12 15:00');

        $this->checkIn($id, ['102'])->assertJsonValidationErrors('rooms.0.room_id'); // dirty
        $this->checkIn($id, ['201'])->assertJsonValidationErrors('rooms.0.room_id'); // other type

        $future = $this->booked(1, '2026-11-20', '2026-11-21');
        $this->checkIn($future, ['101'])->assertJsonValidationErrors('status');

        // Pending (no deposit yet): the deposit can be taken at the desk.
        $this->at('2026-11-12 15:30');
        $pending = $this->postJson('/api/v1/reservations', ['branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2026-11-12', 'check_out' => '2026-11-13', 'guest_id' => $this->guest->id, 'rooms' => [['room_type_id' => $this->standard->id]]]);
        $pending->assertJsonValidationErrors('check_in'); // 14:00 is more than 30 minutes ago
    }

    public function test_pending_booking_takes_the_deposit_at_check_in(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/reservations', ['branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2026-11-12', 'check_out' => '2026-11-13', 'guest_id' => $this->guest->id, 'rooms' => [['room_type_id' => $this->standard->id]]])->json('data.id');
        $this->at('2026-11-12 15:00');

        $this->checkIn($id, ['101'])->assertJsonValidationErrors('payment');
        $this->checkIn($id, ['101'], ['payment' => ['payment_method_id' => $this->cash, 'amount' => '690']])->assertOk()->assertJsonPath('data.status', 'checked_in');
    }

    public function test_walk_in_takes_full_payment_up_front(): void
    {
        $this->at('2026-11-12 16:00');
        $this->actingAs($this->receptionist);
        $walkIn = fn (string $amount) => $this->postJson('/api/v1/front-desk/walk-in', [
            'branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_out' => '2026-11-13', 'guest_id' => $this->guest->id,
            'rooms' => [['room_type_id' => $this->standard->id, 'room_id' => $this->rooms['101']]],
            'payment' => ['payment_method_id' => $this->cash, 'amount' => $amount],
        ]);

        $walkIn('1000')->assertJsonValidationErrors('payment.amount'); // full payment is the default rule
        $this->assertSame('available', $this->room('101')->status);

        $res = $walkIn('2300')->assertCreated()->assertJsonPath('data.status', 'checked_in')->assertJsonPath('data.source', 'walk_in');
        $this->assertSame('occupied', $this->room('101')->status);
        $inv = $this->invoice($res->json('data.id'));
        $this->assertSame('paid', $inv['status']);
        $this->assertFalse(collect($inv['items'])->contains('type', 'early_check_in'));
    }

    public function test_hourly_walk_in_with_deposit_rule(): void
    {
        $this->inHotel(fn () => app(HotelSettings::class)->put(['walk_in_payment' => 'deposit']));
        $this->at('2026-11-12 16:00');
        $this->actingAs($this->receptionist)->postJson('/api/v1/front-desk/walk-in', [
            'branch_id' => $this->branch->id, 'stay_type' => 'hourly', 'hours' => 3, 'guest_id' => $this->guest->id,
            'rooms' => [['room_type_id' => $this->deluxe->id, 'room_id' => $this->rooms['201']]],
            'payment' => ['payment_method_id' => $this->cash, 'amount' => '310.50'], // 30% of 1035
        ])->assertCreated()->assertJsonPath('data.check_out_at', '2026-11-12T19:00:00+03:00');
    }

    public function test_check_out_needs_payment_or_manager_approval(): void
    {
        $id = $this->booked();
        $this->at('2026-11-12 15:00');
        $this->checkIn($id, ['101'])->assertOk();
        $this->at('2026-11-14 11:00');

        $this->actingAs($this->receptionist)->postJson("/api/v1/reservations/$id/check-out")->assertJsonValidationErrors('balance');
        $this->postJson("/api/v1/reservations/$id/check-out", ['approve_balance' => true])->assertJsonValidationErrors('balance'); // reception may not approve

        $this->postJson("/api/v1/reservations/$id/check-out", ['payment' => ['payment_method_id' => $this->cash, 'amount' => '3220']])
            ->assertOk()->assertJsonPath('data.status', 'checked_out');
        $this->assertSame('dirty', $this->room('101')->status);
        $this->assertSame('paid', $this->invoice($id)['status']);
    }

    public function test_manager_can_leave_a_balance_outstanding_and_it_can_be_paid_later(): void
    {
        $id = $this->booked();
        $this->at('2026-11-12 15:00');
        $this->checkIn($id, ['101']);
        $this->at('2026-11-14 11:00');

        $this->postJson("/api/v1/reservations/$id/check-out", ['approve_balance' => true])->assertOk();
        $inv = $this->invoice($id);
        $this->assertSame('outstanding', $inv['status']);

        $this->postJson("/api/v1/invoices/{$inv['id']}/payments", ['type' => 'payment', 'payment_method_id' => $this->cash, 'amount' => '3220'])
            ->assertCreated()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.balance', '0.00');
    }

    public function test_late_check_out_fee_after_the_grace_time(): void
    {
        $id = $this->booked();
        $this->at('2026-11-12 15:00');
        $this->checkIn($id, ['101']);

        $this->at('2026-11-14 12:20');
        $this->getJson("/api/v1/reservations/$id/check-out")->assertJsonPath('data.late_fee', null);
        $this->at('2026-11-14 13:00');
        $this->getJson("/api/v1/reservations/$id/check-out")->assertJsonPath('data.late_fee', '1000.00');

        $this->postJson("/api/v1/reservations/$id/check-out", ['approve_balance' => true])->assertOk();
        $this->assertSame('1000.00', collect($this->invoice($id)['items'])->firstWhere('type', 'late_check_out')['amount']);
    }

    public function test_extras_and_discounts_on_the_bill(): void
    {
        $id = $this->booked();
        $this->at('2026-11-12 15:00');
        $this->checkIn($id, ['101']);
        $invoiceId = $this->invoice($id)['id'];
        $laundry = $this->inHotel(fn () => ChargeType::first()->id);

        $this->actingAs($this->receptionist)->postJson("/api/v1/invoices/$invoiceId/items", ['type' => 'extra', 'charge_type_id' => $laundry, 'quantity' => '2', 'unit_price' => '150'])
            ->assertCreated()->assertJsonPath('data.subtotal', '4300.00')->assertJsonPath('data.tax_total', '645.00');

        $this->postJson("/api/v1/invoices/$invoiceId/items", ['type' => 'discount', 'discount_percent' => '10'])->assertJsonValidationErrors('discount_percent'); // reception max 5%
        $res = $this->postJson("/api/v1/invoices/$invoiceId/items", ['type' => 'discount', 'discount_percent' => '5'])->assertCreated();
        $res->assertJsonPath('data.discount_total', '215.00')->assertJsonPath('data.total', '4697.75'); // (4300 − 215) × 1.15
        $this->postJson("/api/v1/invoices/$invoiceId/items", ['type' => 'discount', 'discount_percent' => '1'])->assertJsonValidationErrors('discount_percent'); // no stacking past 5%

        $extra = collect($res->json('data.items'))->firstWhere('type', 'extra');
        $this->deleteJson("/api/v1/invoices/$invoiceId/items/{$extra['id']}")->assertOk();
        $room = collect($res->json('data.items'))->firstWhere('type', 'room');
        $this->deleteJson("/api/v1/invoices/$invoiceId/items/{$room['id']}")->assertJsonValidationErrors('item');
    }

    public function test_group_bill_splits_per_room(): void
    {
        $id = $this->booked(2);
        $this->at('2026-11-12 15:00');
        $this->checkIn($id, ['101', '102'])->assertOk();
        $invoiceId = $this->invoice($id)['id'];

        $this->postJson("/api/v1/invoices/$invoiceId/split")->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.total', '4600.00')->assertJsonPath('data.1.total', '4600.00')
            ->assertJsonPath('data.0.paid_total', '2760.00'); // the deposit stays on the first bill
    }

    public function test_void_needs_permission_and_a_reason(): void
    {
        $id = $this->booked();
        $this->at('2026-11-12 15:00');
        $this->checkIn($id, ['101']);
        $invoiceId = $this->invoice($id)['id'];

        $this->actingAs($this->receptionist)->postJson("/api/v1/invoices/$invoiceId/void", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->admin)->postJson("/api/v1/invoices/$invoiceId/void")->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/invoices/$invoiceId/void", ['reason' => 'Wrong guest'])->assertOk()->assertJsonPath('data.status', 'void');
        $this->postJson("/api/v1/invoices/$invoiceId/items", ['type' => 'extra', 'unit_price' => '10'])->assertJsonValidationErrors('invoice');
    }

    public function test_room_move_to_a_better_type_charges_the_new_price_from_today(): void
    {
        $id = $this->booked(1, '2026-11-12', '2026-11-15');
        $this->at('2026-11-12 15:00');
        $this->checkIn($id, ['101']);
        $stay = $this->getJson("/api/v1/reservations/$id")->json('data.rooms.0.id');

        $this->at('2026-11-13 10:00');
        $this->postJson("/api/v1/reservations/$id/rooms/$stay/move", ['room_id' => $this->rooms['201']])->assertOk()->assertJsonPath('data.rooms.0.room_number', '201');
        $this->assertSame('dirty', $this->room('101')->status);
        $this->assertSame('occupied', $this->room('201')->status);

        $nights = collect($this->invoice($id)['items'])->where('type', 'room')->pluck('amount', 'service_date')->all();
        $this->assertSame(['2026-11-12' => '2000.00', '2026-11-13' => '3000.00', '2026-11-14' => '3000.00'], $nights);
    }

    public function test_extend_stay_adds_nights_if_the_room_is_free(): void
    {
        $id = $this->booked();
        $this->at('2026-11-12 15:00');
        $this->checkIn($id, ['101']);

        // Room 101 is booked by someone else from the 15th.
        $this->at('2026-11-12 15:05');
        $other = $this->postJson('/api/v1/reservations', ['branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2026-11-15', 'check_out' => '2026-11-16', 'guest_id' => $this->guest->id, 'rooms' => [['room_type_id' => $this->standard->id, 'room_id' => $this->rooms['101']]]])->assertCreated();

        $this->postJson("/api/v1/reservations/$id/extend", ['check_out' => '2026-11-15'])->assertOk()->assertJsonPath('data.check_out_at', '2026-11-15T12:00:00+03:00');
        $this->assertSame('6900.00', $this->invoice($id)['total']); // 3 nights + VAT
        $this->postJson("/api/v1/reservations/$id/extend", ['check_out' => '2026-11-16'])->assertJsonValidationErrors('check_out');
        $this->assertNotNull($other->json('data.id'));
    }

    public function test_room_status_rules(): void
    {
        $this->actingAs($this->receptionist);
        $this->postJson("/api/v1/rooms/{$this->rooms['101']}/status", ['status' => 'dirty'])->assertOk();
        $this->postJson("/api/v1/rooms/{$this->rooms['101']}/status", ['status' => 'available'])->assertOk();
        $this->postJson("/api/v1/rooms/{$this->rooms['101']}/status", ['status' => 'maintenance'])->assertJsonValidationErrors('status');
        $this->postJson("/api/v1/rooms/{$this->rooms['101']}/status", ['status' => 'occupied'])->assertJsonValidationErrors('status');
        $this->actingAs($this->admin)->postJson("/api/v1/rooms/{$this->rooms['101']}/status", ['status' => 'out_of_order'])->assertOk();
    }

    public function test_board_and_today_lists(): void
    {
        $arriving = $this->booked();
        $inHouse = $this->booked(1, '2026-11-12', '2026-11-13');
        $this->at('2026-11-12 15:00');
        $this->checkIn($inHouse, ['102']);

        $board = $this->getJson('/api/v1/front-desk/board')->assertOk()->json('data');
        $r102 = collect($board)->firstWhere('number', '102');
        $this->assertSame('Abebe Kebede', $r102['stay']['guest']);
        $this->assertSame('1610.00', $r102['stay']['balance']);

        $today = $this->getJson('/api/v1/front-desk/today')->assertOk();
        $today->assertJsonPath('arrivals.0.id', $arriving)->assertJsonPath('arrivals.0.late', true)->assertJsonCount(1, 'in_house');
    }

    public function test_room_board_stays_fast_for_big_hotels(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->admin)->getJson("/api/v1/front-desk/board?branch_id={$this->branch->id}")->assertOk();
            $this->getJson("/api/v1/front-desk/today?branch_id={$this->branch->id}")->assertOk();

            return count(DB::getQueryLog());
        };
        $this->checkIn($this->booked(), ['101'])->assertOk();
        $small = $count();

        $this->inHotel(function () {
            foreach (range(1, 120) as $i) {
                Room::create(['branch_id' => $this->branch->id, 'room_type_id' => $i % 2 ? $this->standard->id : $this->deluxe->id, 'number' => (string) (1000 + $i), 'status' => 'available']);
            }
        });
        $this->checkIn($this->booked(), ['102'])->assertOk();

        $this->assertLessThanOrEqual($small + 2, $count(), 'The room board must not run one query per room.');
    }

    public function test_bills_of_another_hotel_are_invisible(): void
    {
        $id = $this->booked();
        $this->at('2026-11-12 15:00');
        $this->checkIn($id, ['101']);
        $invoiceId = $this->invoice($id)['id'];

        $other = Hotel::factory()->create(['plan_id' => $this->hotel->plan_id]);
        Branch::factory()->for($other)->create();
        $stranger = User::factory()->withRole($other, 'hotel_admin')->create();
        $this->actingAs($stranger)->getJson("/api/v1/invoices/$invoiceId")->assertNotFound();
        $this->getJson('/api/v1/invoices')->assertJsonPath('total', 0);
        $this->postJson("/api/v1/reservations/$id/check-out")->assertNotFound();
        $this->postJson("/api/v1/rooms/{$this->rooms['101']}/status", ['status' => 'dirty'])->assertNotFound();
        $this->assertSame(1, $this->inHotel(fn () => Invoice::count()));
    }
}
