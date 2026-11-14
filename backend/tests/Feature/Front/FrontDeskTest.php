<?php

namespace Tests\Feature\Front;

use App\Models\Branch;
use App\Models\ChargeType;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\GuestIdPhotos;
use App\Services\HotelDefaults;
use App\Services\HotelSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    public function test_leaving_early_pays_the_nights_stayed_plus_the_fee(): void
    {
        $id = $this->booked(1, '2026-11-12', '2026-11-15'); // 3 nights: 6000 + 15% = 6900, deposit 2070
        $this->at('2026-11-12 15:00');
        $this->checkIn($id, ['101'])->assertOk();

        $this->at('2026-11-13 10:00'); // leaves after one night
        $info = $this->getJson("/api/v1/reservations/$id/check-out")->assertOk()->json('data.early');
        $this->assertSame(2, $info['nights']);
        $this->assertSame('1150.00', $info['fee']); // half a night (1000) + 15%
        $this->assertSame('1380.00', $info['balance']); // 2000 + 1000 + 15% = 3450 − 2070
        $this->assertSame('230.00', $info['balance_without_fee']);
        $this->assertSame('4830.00', $this->invoice($id)['balance']); // nothing changed by looking

        $this->postJson("/api/v1/reservations/$id/check-out", ['payment' => ['payment_method_id' => $this->cash, 'amount' => '1380']])->assertOk()->assertJsonPath('data.status', 'checked_out');
        $inv = $this->invoice($id);
        $this->assertSame('3450.00', $inv['total']);
        $this->assertSame('0.00', $inv['balance']);
        $this->assertSame(['room', 'early_check_out'], collect($inv['items'])->pluck('type')->all());
        $this->assertSame('2026-11-13', substr((string) $this->getJson("/api/v1/reservations/$id")->json('data.check_out_at'), 0, 10));
    }

    public function test_the_early_check_out_fee_can_be_waived_and_the_first_night_is_always_paid(): void
    {
        $id = $this->booked(1, '2026-11-12', '2026-11-14');
        $this->at('2026-11-12 15:00');
        $this->checkIn($id, ['101'])->assertOk();
        $this->at('2026-11-12 20:00'); // leaves the same evening: the first night still counts
        $this->assertSame(1, $this->getJson("/api/v1/reservations/$id/check-out")->json('data.early.nights'));
        $this->postJson("/api/v1/reservations/$id/check-out", ['waive_early_fee' => true, 'payment' => ['payment_method_id' => $this->cash, 'amount' => '920']])->assertOk();
        $inv = $this->invoice($id);
        $this->assertSame('2300.00', $inv['total']); // one night + tax, fee waived
    }

    public function test_a_walk_in_after_midnight_pays_for_last_night(): void
    {
        $this->at('2026-11-12 01:30'); // before the 06:00 cut-off
        $res = $this->actingAs($this->admin)->postJson('/api/v1/front-desk/walk-in', [
            'branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_out' => '2026-11-12', 'guest_id' => $this->guest->id,
            'rooms' => [['room_type_id' => $this->standard->id, 'room_id' => $this->rooms['101']]],
            'payment' => ['payment_method_id' => $this->cash, 'amount' => '2300'],
        ])->assertCreated();
        $this->assertSame('2026-11-12', substr((string) $res->json('data.check_out_at'), 0, 10)); // leaves the same morning
        $this->assertSame('2300.00', $res->json('data.total')); // one night
        $this->assertSame('2026-11-11', $this->getJson('/api/v1/front-desk/walk-in/rooms?branch_id='.$this->branch->id)->json('night_date'));

        $this->at('2026-11-12 07:00'); // after the cut-off: tonight
        $this->assertSame('2026-11-12', $this->getJson('/api/v1/front-desk/walk-in/rooms?branch_id='.$this->branch->id)->json('night_date'));
    }

    /** A walk-in who does not know how long they stay (Q16), paid for the first night. */
    private function openStay(string $room = '101'): int
    {
        return $this->actingAs($this->receptionist)->postJson('/api/v1/front-desk/walk-in', [
            'branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'open_stay' => true, 'guest_id' => $this->guest->id,
            'rooms' => [['room_type_id' => $this->standard->id, 'room_id' => $this->rooms[$room]]],
            'payment' => ['payment_method_id' => $this->cash, 'amount' => '2300'],
        ])->assertCreated()->assertJsonPath('data.open_stay', true)->assertJsonPath('data.status', 'checked_in')->json('data.id');
    }

    public function test_an_open_stay_adds_a_night_each_day_until_the_guest_leaves(): void
    {
        $this->at('2026-11-12 16:00');
        $id = $this->openStay();
        $this->assertSame('2026-11-13', substr((string) $this->getJson("/api/v1/reservations/$id")->json('data.check_out_at'), 0, 10));
        $this->assertContains($id, collect($this->getJson('/api/v1/front-desk/today?branch_id='.$this->branch->id)->json('departures'))->pluck('id')->all()); // "still staying?"

        $this->at('2026-11-13 12:20'); // inside the late grace: nothing yet
        $this->artisan('stays:roll-open')->assertSuccessful();
        $this->assertSame('2300.00', $this->invoice($id)['total']);

        $this->at('2026-11-13 12:31'); // still in after the check-out time: one more night
        $this->artisan('stays:roll-open')->assertSuccessful();
        $this->artisan('stays:roll-open')->assertSuccessful(); // only once
        $this->assertSame('4600.00', $this->invoice($id)['total']);
        $this->assertSame('2026-11-14', substr((string) $this->getJson("/api/v1/reservations/$id")->json('data.check_out_at'), 0, 10));

        // Leaves the next morning, before the check-out time: no early leave fee, no late fee.
        $this->at('2026-11-14 10:00');
        $info = $this->getJson("/api/v1/reservations/$id/check-out")->assertOk()->json('data');
        $this->assertNull($info['early']);
        $this->assertNull($info['late_fee']);
        $this->postJson("/api/v1/reservations/$id/check-out", ['payment' => ['payment_method_id' => $this->cash, 'amount' => '2300']])->assertOk()->assertJsonPath('data.status', 'checked_out');
        $this->assertSame('4600.00', $this->invoice($id)['total']);
        $this->assertSame('dirty', $this->room('101')->status);

        $this->at('2026-11-15 13:00'); // checked out: nothing more is added
        $this->artisan('stays:roll-open')->assertSuccessful();
        $this->assertSame('4600.00', $this->invoice($id)['total']);
    }

    public function test_an_open_stay_guest_leaving_after_the_check_out_time_pays_a_late_fee_not_a_night(): void
    {
        $this->at('2026-11-12 16:00');
        $id = $this->openStay();
        $this->at('2026-11-13 12:31');
        $this->artisan('stays:roll-open')->assertSuccessful(); // night of the 13th added

        $this->at('2026-11-13 14:00'); // leaves after lunch
        $info = $this->getJson("/api/v1/reservations/$id/check-out")->assertOk()->json('data');
        $this->assertSame('0.00', $info['early']['fee']); // the night not slept comes off, with no early fee
        $this->assertSame('1150.00', $info['late_fee']); // half a night + 15%
        $this->postJson("/api/v1/reservations/$id/check-out", ['payment' => ['payment_method_id' => $this->cash, 'amount' => '1150']])->assertOk();
        $inv = $this->invoice($id);
        $this->assertSame('3450.00', $inv['total']);
        $this->assertSame(['room', 'late_check_out'], collect($inv['items'])->pluck('type')->all());
    }

    public function test_an_open_stay_warns_before_the_room_is_booked_and_never_double_books(): void
    {
        $this->at('2026-11-12 16:00');
        $id = $this->openStay();
        $this->actingAs($this->admin)->postJson('/api/v1/reservations', [ // another guest has room 101 from the 14th
            'branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2026-11-14', 'check_out' => '2026-11-15', 'guest_id' => $this->guest->id,
            'rooms' => [['room_type_id' => $this->standard->id, 'room_id' => $this->rooms['101'], 'adults' => 1]],
        ])->assertCreated();
        $titles = fn () => DB::table('app_notifications')->where('user_id', $this->receptionist->id)->orderBy('id')->pluck('title')->all();

        $this->at('2026-11-13 12:31');
        $this->artisan('stays:roll-open')->assertSuccessful(); // the 13th is free: added; the 14th is not: a warning
        $this->assertSame('4600.00', $this->invoice($id)['total']);
        $this->assertCount(1, $titles());
        $this->assertStringContainsString('room booked after', $titles()[0]);

        $this->at('2026-11-14 12:31');
        $this->artisan('stays:roll-open')->assertSuccessful(); // no night added: the guest must move or leave
        $this->artisan('stays:roll-open')->assertSuccessful();
        $this->assertSame('4600.00', $this->invoice($id)['total']);
        $this->assertCount(2, $titles());
        $this->assertStringContainsString('no night added', $titles()[1]);
        $this->assertTrue($this->getJson('/api/v1/front-desk/board?branch_id='.$this->branch->id)->json('data.0.stay.overdue'));
    }

    public function test_open_stay_is_only_for_nights_and_one_tap_adds_a_night_to_a_normal_stay(): void
    {
        $id = $this->booked();
        $this->at('2026-11-12 16:00');
        $this->actingAs($this->receptionist)->postJson('/api/v1/front-desk/walk-in', [
            'branch_id' => $this->branch->id, 'stay_type' => 'hourly', 'hours' => 3, 'open_stay' => true, 'guest_id' => $this->guest->id,
            'rooms' => [['room_type_id' => $this->deluxe->id, 'room_id' => $this->rooms['201']]],
            'payment' => ['payment_method_id' => $this->cash, 'amount' => '1035'],
        ])->assertJsonValidationErrors('open_stay');

        // "+1 night" on the board = extend to the next day.
        $this->checkIn($id, ['102'])->assertOk();
        $this->postJson("/api/v1/reservations/$id/extend", ['check_out' => '2026-11-15'])->assertOk();
        $this->assertSame('2026-11-15', substr((string) $this->getJson("/api/v1/reservations/$id")->json('data.check_out_at'), 0, 10));
        $this->assertFalse((bool) $this->getJson("/api/v1/reservations/$id")->json('data.open_stay'));
    }

    public function test_quick_buttons_charge_a_room_in_one_tap_and_can_be_undone(): void
    {
        [$breakfast, $taxi] = $this->inHotel(fn () => [
            ChargeType::create(['name' => 'Breakfast', 'default_price' => '250', 'currency' => 'ETB', 'is_active' => true, 'is_quick' => true, 'sort_order' => 1])->id,
            ChargeType::create(['name' => 'Taxi', 'default_price' => null, 'currency' => 'ETB', 'is_active' => true, 'is_quick' => true, 'sort_order' => 2])->id,
        ]);
        $this->at('2026-11-12 16:00');
        $id = $this->openStay(); // room 101, paid 2300
        $room = $this->rooms['101'];

        $list = $this->getJson('/api/v1/front-desk/quick-charges?branch_id='.$this->branch->id)->assertOk();
        $this->assertSame([['101', 'Abebe K']], collect($list->json('rooms'))->map(fn ($r) => [$r['number'], $r['guest']])->all());
        $this->assertSame(['Breakfast', 'Taxi'], collect($list->json('buttons'))->pluck('name')->all()); // only quick buttons (not Laundry), in order

        $one = $this->postJson("/api/v1/front-desk/rooms/$room/charges", ['charge_type_id' => $breakfast])->assertCreated()->assertJsonPath('data.amount', '250.00')->json('data.id');
        $this->postJson("/api/v1/front-desk/rooms/$room/charges", ['charge_type_id' => $breakfast])->assertCreated(); // tap again: two breakfasts
        $this->postJson("/api/v1/front-desk/rooms/$room/charges", ['charge_type_id' => $taxi])->assertJsonValidationErrors('unit_price'); // no price: type it
        $this->postJson("/api/v1/front-desk/rooms/$room/charges", ['charge_type_id' => $taxi, 'unit_price' => '300'])->assertCreated();
        $this->postJson("/api/v1/front-desk/rooms/$room/charges", ['description' => 'Spa', 'unit_price' => '500'])->assertCreated()->assertJsonPath('data.description', 'Spa');
        $this->assertSame('3300.00', $this->invoice($id)['subtotal']); // 2000 + 250 + 250 + 300 + 500
        $this->assertSame('1495.00', $this->invoice($id)['balance']); // 3300 + 15% − 2300
        // Every place that shows the stay's money shows the extras too (the bill is the truth).
        $detail = $this->getJson("/api/v1/reservations/$id")->json('data');
        $this->assertSame(['3795.00', '2300.00', '2300.00', '1495.00'], [$detail['total'], $detail['paid_total'], $detail['booking_total'], $detail['balance']]);
        $this->assertSame('3795.00', collect($this->getJson('/api/v1/reservations?status=checked_in')->json('data'))->firstWhere('id', $id)['total']);
        $this->assertSame('3795.00', collect($this->actingAs($this->admin)->getJson('/api/v1/guests/'.$this->guest->id)->json('stays'))->firstWhere('id', $id)['total']);
        $this->actingAs($this->receptionist);

        $this->actingAs($this->admin)->deleteJson("/api/v1/front-desk/charges/$one")->assertNotFound(); // only the person who added it
        $this->actingAs($this->receptionist)->deleteJson("/api/v1/front-desk/charges/$one")->assertOk();
        $this->deleteJson("/api/v1/front-desk/charges/$one")->assertNotFound();
        $this->assertSame('3050.00', $this->invoice($id)['subtotal']);
        $late = $this->postJson("/api/v1/front-desk/rooms/$room/charges", ['charge_type_id' => $breakfast])->json('data.id');
        $this->at('2026-11-12 16:11'); // too late to undo: remove it on the bill instead
        $this->deleteJson("/api/v1/front-desk/charges/$late")->assertNotFound();

        $this->postJson('/api/v1/front-desk/rooms/'.$this->rooms['102'].'/charges', ['charge_type_id' => $breakfast])->assertJsonValidationErrors('room'); // nobody in 102
    }

    public function test_restaurant_and_bar_staff_can_only_charge_rooms(): void
    {
        $this->at('2026-11-12 16:00');
        $this->openStay();
        $bar = User::factory()->withRole($this->hotel, 'restaurant')->create();
        $bar->branches()->attach($this->branch->id);
        $type = $this->inHotel(fn () => ChargeType::create(['name' => 'Coffee', 'default_price' => '60', 'currency' => 'ETB', 'is_active' => true, 'is_quick' => true])->id);

        $this->actingAs($bar)->getJson('/api/v1/front-desk/quick-charges')->assertOk()->assertJsonCount(1, 'rooms');
        $this->postJson('/api/v1/front-desk/rooms/'.$this->rooms['101'].'/charges', ['charge_type_id' => $type])->assertCreated();
        foreach (['/api/v1/front-desk/board', '/api/v1/reservations', '/api/v1/invoices', '/api/v1/guests'] as $url) {
            $this->getJson($url)->assertForbidden();
        }

        // Another hotel's room cannot be charged.
        $other = Hotel::factory()->create(['plan_id' => $this->hotel->plan_id]);
        $otherRoom = app(TenantContext::class)->runAs($other, function () use ($other) {
            $branch = Branch::factory()->for($other)->create();
            $type = RoomType::create(['branch_id' => $branch->id, 'name' => 'X', 'base_capacity' => 1, 'max_capacity' => 1, 'nightly_price' => '100', 'currency' => 'ETB']);

            return Room::create(['branch_id' => $branch->id, 'room_type_id' => $type->id, 'number' => '9', 'status' => 'available'])->id;
        });
        $this->postJson("/api/v1/front-desk/rooms/$otherRoom/charges", ['charge_type_id' => $type])->assertNotFound();
    }

    public function test_the_bill_shows_a_paying_company_and_never_the_guest_id(): void
    {
        $this->inHotel(fn () => $this->guest->update(['id_number' => 'ET1234567', 'phone' => '+251911223344']));
        $id = $this->booked();
        $this->at('2026-11-12 15:00');
        $this->checkIn($id, ['101'])->assertOk();
        $invoiceId = $this->getJson("/api/v1/reservations/$id")->json('data.invoices.0.id');

        $bill = $this->getJson("/api/v1/invoices/$invoiceId")->assertOk()->json('data');
        $this->assertArrayNotHasKey('id_number', $bill['guest']); // the ID stays on the guest's page and the registration card
        $this->assertSame(['company' => null, 'tin' => null], $bill['buyer']);

        $this->actingAs($this->receptionist)->putJson("/api/v1/invoices/$invoiceId/buyer", ['company' => 'Abay Trading PLC', 'tin' => '0098765432'])->assertOk()
            ->assertJsonPath('data.buyer', ['company' => 'Abay Trading PLC', 'tin' => '0098765432']);
        $this->putJson("/api/v1/invoices/$invoiceId/buyer", ['company' => 'Abay', 'tin' => 'ABC-1'])->assertJsonValidationErrors('tin');
        $this->putJson("/api/v1/invoices/$invoiceId/buyer", ['tin' => '0098765432'])->assertJsonPath('errors.company.0', 'Type the name of the company for this TIN.');
        $this->postJson("/api/v1/invoices/$invoiceId/items", ['type' => 'extra', 'description' => 'Old', 'unit_price' => '10', 'service_date' => '2020-01-01'])
            ->assertJsonPath('errors.service_date.0', 'Choose a date in the last year.');
        $this->putJson("/api/v1/invoices/$invoiceId/buyer", ['company' => null, 'tin' => null])->assertOk()->assertJsonPath('data.buyer.company', null); // removed

        $cleaner = User::factory()->withRole($this->hotel, 'housekeeping')->create();
        $cleaner->branches()->attach($this->branch->id);
        $this->actingAs($cleaner)->putJson("/api/v1/invoices/$invoiceId/buyer", ['company' => 'X'])->assertForbidden();
    }

    public function test_payments_list_filters_by_branch_only_within_the_persons_branches(): void
    {
        $piassa = Branch::factory()->for($this->hotel)->create(['name' => 'Piassa', 'code' => 'PS']);
        $this->inHotel(function () use ($piassa) {
            foreach ([[$this->branch->id, '100.00'], [$piassa->id, '250.00']] as [$branchId, $amount]) {
                Payment::forceCreate(['branch_id' => $branchId, 'payment_method_id' => $this->cash, 'type' => 'payment', 'amount' => $amount,
                    'currency' => 'ETB', 'amount_in_base' => $amount, 'paid_at' => now()]);
            }
        });
        $other = Branch::factory()->for(Hotel::factory()->create())->create();

        $this->actingAs($this->admin);
        $this->getJson('/api/v1/payments')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/payments?branch_id='.$piassa->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', '250.00');
        $this->getJson('/api/v1/payments?branch_id='.$other->id)->assertOk()->assertJsonCount(0, 'data'); // another hotel's branch: nothing

        // A branch manager who works only in Bole never sees Piassa, even when asking for it.
        $manager = User::factory()->withRole($this->hotel, 'branch_manager')->create();
        $manager->branches()->attach($this->branch->id);
        $this->actingAs($manager);
        $this->getJson('/api/v1/payments?branch_id='.$piassa->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/payments')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', '100.00');
    }

    public function test_lists_filter_by_date_and_the_board_shows_another_day(): void
    {
        $id = $this->booked(); // 12 → 14 Nov
        $this->at('2026-11-12 15:00');
        $this->checkIn($id, ['101'])->assertOk();
        $this->actingAs($this->admin)->postJson('/api/v1/reservations', [ // room 102 booked for 20 → 21 Nov
            'branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2026-11-20', 'check_out' => '2026-11-21', 'guest_id' => $this->guest->id,
            'rooms' => [['room_type_id' => $this->standard->id, 'room_id' => $this->rooms['102'], 'adults' => 1]],
        ])->assertCreated();

        // Bills by their date.
        $this->assertCount(1, $this->getJson('/api/v1/invoices?from=2026-11-12&to=2026-11-12')->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/invoices?from=2026-11-13')->json('data'));
        // Guests who stayed on those days (a booking that has not started does not count).
        $this->assertSame(['Abebe Kebede'], collect($this->getJson('/api/v1/guests?stayed_from=2026-11-13&stayed_to=2026-11-13')->json('data'))->pluck('full_name')->all());
        $this->assertCount(0, $this->getJson('/api/v1/guests?stayed_from=2026-11-20&stayed_to=2026-11-21')->json('data'));
        // Activity log by day.
        $this->assertGreaterThan(0, $this->getJson('/api/v1/activity-log?from=2026-11-12&to=2026-11-12')->json('total'));
        $this->assertSame(0, $this->getJson('/api/v1/activity-log?from=2026-11-15')->json('total'));

        // The room board on another day: who stays that night.
        $room = fn (array $rooms, string $n) => collect($rooms)->firstWhere('number', $n);
        $on13 = $this->getJson('/api/v1/front-desk/board?branch_id='.$this->branch->id.'&date=2026-11-13')->assertOk()->json('data');
        $this->assertSame(['occupied', 'Abebe Kebede', 'checked_in'], [$room($on13, '101')['status'], $room($on13, '101')['stay']['guest'], $room($on13, '101')['stay']['status']]);
        $this->assertSame('available', $room($on13, '102')['status']);
        $on14 = $this->getJson('/api/v1/front-desk/board?branch_id='.$this->branch->id.'&date=2026-11-14')->json('data');
        $this->assertSame('available', $room($on14, '101')['status']); // leaves on the 14th: free that night
        $on20 = $this->getJson('/api/v1/front-desk/board?branch_id='.$this->branch->id.'&date=2026-11-20')->json('data');
        $this->assertSame(['occupied', 'pending'], [$room($on20, '102')['status'], $room($on20, '102')['stay']['status']]); // booked, deposit not paid yet
        $this->getJson('/api/v1/front-desk/board?branch_id='.$this->branch->id.'&date=12-11-2026')->assertJsonValidationErrors('date');
    }

    public function test_lists_sort_page_and_download_for_people_allowed_to_export(): void
    {
        $first = $this->booked();
        $second = $this->booked();
        $this->at('2026-11-12 15:00');
        $this->checkIn($first, ['101'])->assertOk();
        $codes = fn ($r) => collect($r->json('data'))->pluck('code')->all();

        $asc = $codes($this->actingAs($this->admin)->getJson('/api/v1/reservations?sort=code&dir=asc')->assertOk());
        $desc = $codes($this->getJson('/api/v1/reservations?sort=code&dir=desc'));
        $this->assertSame(array_reverse($asc), $desc);
        $this->getJson('/api/v1/reservations?sort=code;drop table reservations&dir=sideways')->assertOk(); // unknown sort: the default order
        $this->assertSame(50, $this->getJson('/api/v1/reservations?per_page=50')->json('per_page'));
        $this->assertSame(25, $this->getJson('/api/v1/reservations?per_page=7')->json('per_page')); // only 25 / 50 / 100

        // Excel / CSV with the same filters (all rows), only with the export permission.
        $file = $this->get('/api/v1/reservations?format=xlsx&status=checked_in')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $file->headers->get('Content-Type'));
        $csv = $this->get('/api/v1/invoices?format=csv')->assertOk()->getContent();
        $this->assertStringContainsString('Bill,Date,Guest', $csv);
        $this->inHotel(fn () => $this->guest->update(['id_number' => 'ET1234567']));
        $guests = $this->get('/api/v1/guests?format=csv')->assertOk()->getContent();
        $this->assertStringContainsString('Abebe', $guests);
        $this->assertStringNotContainsString('ET1234567', $guests); // the ID number stays masked
        $this->assertStringContainsString('Booking,Guest', $this->get('/api/v1/reservations?format=csv')->getContent());

        $this->actingAs($this->receptionist)->get('/api/v1/reservations?format=xlsx')->assertForbidden(); // no reports.export
        $this->get('/api/v1/guests?format=csv')->assertForbidden(); // guests.export is the Hotel Admin's
        $this->getJson('/api/v1/reservations?sort=guest&dir=asc')->assertOk(); // sorting is for everyone who sees the list
    }

    public function test_paying_more_than_owed_needs_a_yes(): void
    {
        $id = $this->booked();
        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'payment', 'payment_method_id' => $this->cash, 'amount' => '99999'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirm_overpay');
        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'payment', 'payment_method_id' => $this->cash, 'amount' => '3220'])->assertCreated(); // exactly the rest
        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'payment', 'payment_method_id' => $this->cash, 'amount' => '100', 'confirm_overpay' => true])->assertCreated();
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

    public function test_a_booked_guest_arriving_after_midnight_pays_a_full_night(): void
    {
        $id = $this->booked();
        $this->at('2026-11-12 00:30'); // the booking starts at 14:00 today; the night cut-off is 06:00
        $this->actingAs($this->receptionist);
        $this->getJson("/api/v1/reservations/$id/check-in")
            ->assertJsonPath('data.rooms.0.early_fee', '2000.00') // a full night, not 50%
            ->assertJsonPath('data.rooms.0.early_is_night', true);

        $this->checkIn($id, ['101'])->assertOk();
        $line = collect($this->invoice($id)['items'])->firstWhere('type', 'early_check_in');
        $this->assertSame('2000.00', $line['amount']);
        $this->assertStringStartsWith('Last night (arrived after midnight)', $line['description']);
        $this->assertSame('2026-11-11', $line['service_date']);
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

    public function test_an_hourly_guest_who_stays_longer_pays_the_extra_hours(): void
    {
        $this->at('2026-11-12 16:00');
        $id = $this->actingAs($this->admin)->postJson('/api/v1/front-desk/walk-in', [
            'branch_id' => $this->branch->id, 'stay_type' => 'hourly', 'hours' => 3, 'guest_id' => $this->guest->id,
            'rooms' => [['room_type_id' => $this->deluxe->id, 'room_id' => $this->rooms['201']]],
            'payment' => ['payment_method_id' => $this->cash, 'amount' => '1035'], // 3 × 300 + 15%
        ])->assertCreated()->json('data.id');

        $this->at('2026-11-12 19:10'); // within 15 minutes: nothing extra
        $this->assertNull($this->getJson("/api/v1/reservations/$id/check-out")->json('data.late_fee'));
        $this->at('2026-11-12 20:20'); // 1 h 20 min late: 2 started hours
        $this->assertSame('690.00', $this->getJson("/api/v1/reservations/$id/check-out")->json('data.late_fee')); // 2 × 300 + 15%
        $this->postJson("/api/v1/reservations/$id/check-out", ['payment' => ['payment_method_id' => $this->cash, 'amount' => '690']])->assertOk();
        $line = collect($this->invoice($id)['items'])->firstWhere('type', 'late_check_out');
        $this->assertSame('600.00', $line['amount']);
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

        // The guest has left: reception can no longer add or remove lines on the bill; the Hotel Admin can.
        $bill = $this->invoice($id)['id'];
        $this->postJson("/api/v1/invoices/$bill/items", ['type' => 'extra', 'description' => 'Minibar', 'unit_price' => '50'])
            ->assertJsonPath('errors.invoice.0', 'The guest has left. Only a manager can change this bill now.');
        $this->actingAs($this->admin)->postJson("/api/v1/invoices/$bill/items", ['type' => 'extra', 'description' => 'Minibar', 'unit_price' => '50'])->assertCreated();
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
        $info = $this->getJson("/api/v1/reservations/$id/check-out")->assertJsonPath('data.late_fee', '1150.00')->json('data'); // 1000 + 15% tax
        $due = bcadd($info['balance'], $info['late_fee'], 2);

        // Paying exactly "balance + late fee" settles the bill: no manager needed.
        $this->actingAs($this->receptionist)->postJson("/api/v1/reservations/$id/check-out", ['payment' => ['payment_method_id' => $this->cash, 'amount' => $due]])->assertOk();
        $this->assertSame('1000.00', collect($this->invoice($id)['items'])->firstWhere('type', 'late_check_out')['amount']);
        $this->assertSame('paid', $this->invoice($id)['status']);
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

        // One payment at check-out pays both bills, so nobody needs a manager's approval.
        $this->at('2026-11-14 11:00');
        $this->postJson("/api/v1/reservations/$id/check-out", ['payment' => ['payment_method_id' => $this->cash, 'amount' => '6440.00']])
            ->assertOk()->assertJsonPath('data.status', 'checked_out');
        $bills = $this->getJson("/api/v1/reservations/$id")->json('data.invoices');
        $this->assertSame(['paid', 'paid'], array_column($bills, 'status'));
        $this->assertSame('0.00', $this->getJson("/api/v1/reservations/$id")->json('data.balance'));
    }

    public function test_a_new_bill_after_a_void_keeps_the_payments(): void
    {
        $id = $this->booked();
        $this->at('2026-11-12 15:00');
        $this->checkIn($id, ['101'])->assertOk();
        $invoiceId = $this->invoice($id)['id'];
        $this->postJson("/api/v1/invoices/$invoiceId/void", ['reason' => 'Wrong name'])->assertOk();

        $this->postJson("/api/v1/reservations/$id/extend", ['check_out' => '2026-11-15'])->assertOk(); // opens a new bill
        $open = collect($this->getJson("/api/v1/reservations/$id")->json('data.invoices'))->firstWhere('status', '!=', 'void');
        $bill = $this->getJson("/api/v1/invoices/{$open['id']}")->json('data');
        $this->assertNotSame($invoiceId, $bill['id']);
        $this->assertSame('1380.00', $bill['paid_total']); // the deposit moved over
        $this->assertSame('6900.00', $bill['total']); // 3 nights × 2000 + 15%
        $this->assertSame('6900.00', $this->getJson("/api/v1/reservations/$id")->json('data.total')); // booking total follows
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

    public function test_walk_in_offers_only_rooms_free_for_the_whole_stay(): void
    {
        // Room 101 is clean now but booked from tomorrow.
        $this->actingAs($this->admin)->postJson('/api/v1/reservations', [
            'branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2026-11-13', 'check_out' => '2026-11-15', 'guest_id' => $this->guest->id,
            'rooms' => [['room_type_id' => $this->standard->id, 'room_id' => $this->rooms['101'], 'adults' => 1]],
        ])->assertCreated();

        $free = fn (string $qs) => collect($this->getJson("/api/v1/front-desk/walk-in/rooms?branch_id={$this->branch->id}&{$qs}")->assertOk()->json('data'))->pluck('number')->all();
        $this->assertContains('101', $free('stay_type=nightly&check_out=2026-11-13')); // one night: leaves before the booking
        $this->assertNotContains('101', $free('stay_type=nightly&check_out=2026-11-16')); // four nights: overlaps
        $this->assertContains('102', $free('stay_type=nightly&check_out=2026-11-16'));
    }

    public function test_quick_check_in_with_name_and_phone_and_a_reminder_for_the_rest(): void
    {
        $this->at('2026-11-12 16:00');
        $this->actingAs($this->receptionist);
        $quick = fn (array $guest, string $room = '101') => $this->postJson('/api/v1/front-desk/walk-in', [
            'branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_out' => '2026-11-13', 'quick_guest' => $guest,
            'rooms' => [['room_type_id' => $this->standard->id, 'room_id' => $this->rooms[$room]]],
            'payment' => ['payment_method_id' => $this->cash, 'amount' => '2300'], // the hotel's payment rule still applies
        ]);

        $quick(['name' => 'Almaz'])->assertJsonValidationErrors('quick_guest.phone');
        $res = $quick(['name' => 'Almaz Desta', 'phone' => '+251 911 000111'])->assertCreated()->assertJsonPath('data.status', 'checked_in');
        $guest = $this->inHotel(fn () => Guest::findOrFail($res->json('data.guest.id')));
        $this->assertSame(['Almaz', 'Desta', '+251 911 000111'], [$guest->first_name, $guest->last_name, $guest->phone]);
        $this->assertTrue($guest->details_missing);

        // Easy to find and finish later.
        $this->getJson('/api/v1/guests?missing=1')->assertOk()->assertJsonCount(2, 'data'); // also the setup guest without an ID
        $this->putJson("/api/v1/guests/{$guest->id}", ['first_name' => 'Almaz', 'last_name' => 'Desta', 'phone' => '+251 911 000111', 'id_type' => 'national_id', 'id_number' => 'ET1234567', 'nationality' => 'Ethiopian'])->assertOk();
        $this->assertFalse($this->inHotel(fn () => Guest::findOrFail($guest->id))->details_missing);

        // A reminder hours later goes to the person who checked the guest in, once, while details are missing.
        $res2 = $quick(['name' => 'Kebede', 'phone' => '0911 222333'], '102')->assertCreated();
        $this->at('2026-11-12 18:30');
        $this->artisan('automations:run')->assertSuccessful();
        $this->artisan('automations:run')->assertSuccessful();
        $titles = DB::table('app_notifications')->where('user_id', $this->receptionist->id)->where('source', 'guest_details_missing')->pluck('title')->all();
        $this->assertSame(['Finish the guest details for Kebede'], $titles, 'Reservation '.$res2->json('data.id'));

        // Without the permission: refused, and nothing is saved.
        DB::table('role_permissions')->where('role_id', $this->receptionist->role_id)->where('permission', 'frontdesk.quick_check_in')->delete();
        $this->receptionist->forgetPermissionCache();
        $before = $this->inHotel(fn () => Guest::count());
        $quick(['name' => 'Saba', 'phone' => '0911 444555'], '201')->assertForbidden();
        $this->assertSame($before, $this->inHotel(fn () => Guest::count()));
    }

    public function test_pay_at_check_out_when_the_hotel_allows_it(): void
    {
        $this->at('2026-11-12 09:00');
        $booking = fn (array $extra = []) => $this->postJson('/api/v1/reservations', [
            'branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2026-11-12', 'check_out' => '2026-11-14', 'guest_id' => $this->guest->id,
            'rooms' => [['room_type_id' => $this->standard->id, 'adults' => 2]],
        ] + $extra);

        // Off by default: nobody can skip the deposit.
        $this->actingAs($this->admin);
        $booking(['pay_later' => true])->assertJsonValidationErrors('pay_later');
        $this->getJson('/api/v1/reservations/lookups')->assertJsonPath('pay_later', false);

        $this->inHotel(fn () => app(HotelSettings::class)->put(['allow_pay_later' => true]));
        $this->getJson('/api/v1/reservations/lookups')->assertJsonPath('pay_later', true);

        // Receptionists do not have the permission by default.
        $this->actingAs($this->receptionist)->getJson('/api/v1/reservations/lookups')->assertJsonPath('pay_later', false);
        $booking(['pay_later' => true])->assertJsonValidationErrors('pay_later');

        // A manager (here the Hotel Admin) books without a deposit: confirmed at once, nothing paid.
        $id = $this->actingAs($this->admin)->postJson('/api/v1/reservations', [
            'branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2026-11-12', 'check_out' => '2026-11-14', 'guest_id' => $this->guest->id,
            'rooms' => [['room_type_id' => $this->standard->id, 'adults' => 2]], 'pay_later' => true,
        ])->assertCreated()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.paid_total', '0.00')->json('data.id');
        $this->getJson("/api/v1/reservations/$id")->assertJsonPath('data.pay_later.by', $this->admin->name);

        // A pending booking can be switched to "pay at check-out".
        $pending = $booking()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->postJson("/api/v1/reservations/$pending/pay-later")->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->postJson("/api/v1/reservations/$pending/pay-later")->assertJsonValidationErrors('status');

        // Checks in without paying, but pays everything before leaving.
        $this->at('2026-11-12 15:00');
        $this->checkIn($id, ['101'])->assertOk()->assertJsonPath('data.status', 'checked_in');
        $this->postJson("/api/v1/reservations/$id/check-out")->assertJsonValidationErrors('balance');

        // Walk-in without paying now.
        $this->postJson('/api/v1/front-desk/walk-in', [
            'branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_out' => '2026-11-13', 'guest_id' => $this->guest->id, 'pay_later' => true,
            'rooms' => [['room_type_id' => $this->deluxe->id, 'room_id' => $this->rooms['201']]],
        ])->assertCreated()->assertJsonPath('data.status', 'checked_in')->assertJsonPath('data.paid_total', '0.00');
    }

    public function test_payment_proof_photo_is_optional_private_and_can_be_added_later(): void
    {
        Storage::fake('local');
        $this->actingAs($this->receptionist);
        $id = $this->postJson('/api/v1/reservations', [
            'branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2026-11-12', 'check_out' => '2026-11-13', 'guest_id' => $this->guest->id,
            'rooms' => [['room_type_id' => $this->standard->id]],
        ])->assertCreated()->json('data.id');

        // Only images and PDFs.
        $this->postJson('/api/v1/payment-proofs', ['file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')])->assertJsonValidationErrors('file');
        $proof = $this->postJson('/api/v1/payment-proofs', ['file' => UploadedFile::fake()->image('slip.jpg', 800, 600)])->assertCreated()->json('data.id');

        // Sent with the payment.
        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'deposit', 'payment_method_id' => $this->cash, 'amount' => '690', 'proof_id' => $proof])->assertCreated();
        $payment = $this->getJson("/api/v1/reservations/$id")->assertJsonPath('data.payments.0.proof.id', $proof)->json('data.payments.0.id');
        $this->get("/api/v1/payment-proofs/$proof")->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        // A proof is used once.
        $this->putJson("/api/v1/payments/$payment/proof", ['proof_id' => $proof])->assertJsonValidationErrors('proof_id');

        // Added later to a payment without proof (e.g. taken at check-out).
        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'payment', 'payment_method_id' => $this->cash, 'amount' => '100'])->assertCreated();
        $second = collect($this->getJson("/api/v1/reservations/$id")->json('data.payments'))->firstWhere('proof', null)['id'];
        $pdf = $this->postJson('/api/v1/payment-proofs', ['file' => UploadedFile::fake()->create('slip.pdf', 50, 'application/pdf')])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/payments/$second/proof", ['proof_id' => $pdf])->assertOk();

        // Another hotel never sees it.
        $other = Hotel::factory()->create();
        Branch::factory()->for($other)->create();
        $stranger = User::factory()->withRole($other, 'hotel_admin')->create();
        $this->actingAs($stranger)->get("/api/v1/payment-proofs/$proof")->assertNotFound();

        // Housekeeping cannot upload or look.
        $cleaner = User::factory()->withRole($this->hotel, 'housekeeping')->create();
        $this->actingAs($cleaner)->get("/api/v1/payment-proofs/$proof")->assertForbidden();
        $this->postJson('/api/v1/payment-proofs', ['file' => UploadedFile::fake()->image('x.jpg')])->assertForbidden();
    }

    public function test_room_board_shows_the_next_future_booking_of_a_room(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/reservations', [
            'branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2026-11-20', 'check_out' => '2026-11-22', 'guest_id' => $this->guest->id,
            'rooms' => [['room_type_id' => $this->standard->id, 'room_id' => $this->rooms['102'], 'adults' => 1]],
        ])->assertCreated();

        $rooms = collect($this->getJson("/api/v1/front-desk/board?branch_id={$this->branch->id}")->assertOk()->json('data'))->keyBy('number');
        $this->assertSame('2026-11-20T14:00:00+03:00', $rooms['102']['next_booking']['check_in_at']);
        $this->assertNull($rooms['102']['arriving']);
        $this->assertNull($rooms['101']['next_booking']);
    }

    public function test_guest_id_photo_is_encrypted_can_be_required_and_is_removed_later(): void
    {
        Storage::fake('local');
        $id = $this->booked(); // 12 → 14 Nov, made at 09:00
        $photo = UploadedFile::fake()->image('passport.jpg', 600, 400);
        $original = (string) file_get_contents($photo->getRealPath());

        // Off by default.
        $this->actingAs($this->receptionist)->post("/api/v1/guests/{$this->guest->id}/id-photo", ['file' => $photo], ['Accept' => 'application/json'])->assertJsonValidationErrors('file');

        $this->inHotel(fn () => app(HotelSettings::class)->put(['id_photo_enabled' => true, 'id_photo_required' => true]));
        $this->getJson('/api/v1/reservations/lookups')->assertJsonPath('id_photo.required', true);
        $this->at('2026-11-12 15:00');
        $this->getJson("/api/v1/reservations/$id/check-in")->assertJsonPath('data.id_photo_needed', true);
        $this->checkIn($id, ['101'])->assertJsonValidationErrors('id_photo');

        // Taken: stored encrypted, shown as it was.
        $this->post("/api/v1/guests/{$this->guest->id}/id-photo", ['file' => $photo], ['Accept' => 'application/json'])->assertCreated();
        $stored = Storage::disk('local')->allFiles('guest-ids');
        $this->assertCount(1, $stored);
        $this->assertNotSame($original, Storage::disk('local')->get($stored[0]));
        // Shown as a picture of the same size (drawn again on upload, without hidden data – SECURITY_REPORT L4).
        $shown = (string) $this->get("/api/v1/guests/{$this->guest->id}/id-photo")->assertOk()->getContent();
        $this->assertSame(array_slice(getimagesizefromstring($original), 0, 2), array_slice(getimagesizefromstring($shown), 0, 2));
        $this->getJson("/api/v1/guests/{$this->guest->id}")->assertJsonPath('id_photo.mime', 'image/jpeg');
        $this->checkIn($id, ['101'])->assertOk();

        // Quick check-in is never blocked: the photo is added later.
        $this->postJson('/api/v1/front-desk/walk-in', [
            'branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_out' => '2026-11-13', 'quick_guest' => ['name' => 'Saba Tesfaye', 'phone' => '0911 555666'],
            'rooms' => [['room_type_id' => $this->standard->id, 'room_id' => $this->rooms['102']]],
            'payment' => ['payment_method_id' => $this->cash, 'amount' => '2300'],
        ])->assertCreated();

        // Another hotel never sees it; housekeeping neither.
        $other = Hotel::factory()->create();
        Branch::factory()->for($other)->create();
        $this->actingAs(User::factory()->withRole($other, 'hotel_admin')->create())->get("/api/v1/guests/{$this->guest->id}/id-photo")->assertNotFound();
        $this->actingAs(User::factory()->withRole($this->hotel, 'housekeeping')->create())->get("/api/v1/guests/{$this->guest->id}/id-photo")->assertForbidden();

        // Removed some time after the last stay (12 months by default).
        $this->at('2027-12-01 09:00');
        $this->inHotel(fn () => app(GuestIdPhotos::class)->cleanUp($this->hotel));
        $this->assertSame([], Storage::disk('local')->allFiles('guest-ids'));
    }
}
