<?php

namespace Tests\Feature\Front;

use App\Models\Branch;
use App\Models\ExchangeRate;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\HotelDefaults;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ReservationsTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private Branch $branch;

    private User $admin;

    private RoomType $standard;

    private RoomType $deluxe;

    private Guest $guest;

    private int $cash;

    protected function setUp(): void
    {
        parent::setUp();
        // Tuesday 10 Nov 2026, 09:00 in Addis Ababa.
        $this->travelTo(Carbon::parse('2026-11-10 09:00', 'Africa/Addis_Ababa'));

        $this->hotel = Hotel::factory()->create(['plan_id' => Plan::factory()->create(['max_rooms' => null, 'modules' => []])->id, 'default_currency' => 'ETB']);
        $this->branch = Branch::factory()->for($this->hotel)->create(['name' => 'Bole', 'code' => 'BL', 'check_in_time' => '14:00', 'check_out_time' => '12:00']);
        app(HotelDefaults::class)->apply($this->hotel);
        $this->admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();

        $this->inHotel(function () {
            $this->standard = RoomType::create(['branch_id' => $this->branch->id, 'name' => 'Standard', 'base_capacity' => 2, 'max_capacity' => 2, 'nightly_price' => '2000', 'currency' => 'ETB']);
            $this->deluxe = RoomType::create(['branch_id' => $this->branch->id, 'name' => 'Deluxe', 'base_capacity' => 2, 'max_capacity' => 3, 'nightly_price' => '3000', 'day_use_price' => '1200', 'hourly_price' => '300', 'currency' => 'ETB']);
            foreach (['101', '102'] as $n) {
                Room::create(['branch_id' => $this->branch->id, 'room_type_id' => $this->standard->id, 'number' => $n, 'status' => 'available']);
            }
            Room::create(['branch_id' => $this->branch->id, 'room_type_id' => $this->deluxe->id, 'number' => '201', 'status' => 'available']);
            $this->guest = Guest::create(['first_name' => 'Abebe', 'last_name' => 'Kebede', 'phone' => '+251 911 223344']);
            $this->cash = PaymentMethod::where('name', 'Cash')->value('id');
        });
    }

    private function inHotel(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->hotel, $fn);
    }

    /** @return array<string, mixed> */
    private function booking(array $extra = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2026-11-12', 'check_out' => '2026-11-14',
            'guest_id' => $this->guest->id, 'rooms' => [['room_type_id' => $this->standard->id, 'adults' => 2]],
        ], $extra);
    }

    public function test_availability_shows_free_types_prices_and_rooms(): void
    {
        $res = $this->actingAs($this->admin)->getJson('/api/v1/reservations/availability?'.http_build_query([
            'branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2026-11-12', 'check_out' => '2026-11-14',
        ]))->assertOk();

        $res->assertJsonPath('data.0.name', 'Standard')->assertJsonPath('data.0.available', 2)->assertJsonPath('data.0.price', '4000.00')
            ->assertJsonPath('data.0.free_rooms.0.number', '101')
            ->assertJsonPath('check_in_at', '2026-11-12T14:00:00+03:00')->assertJsonPath('check_out_at', '2026-11-14T12:00:00+03:00');
    }

    public function test_new_booking_is_priced_with_tax_and_waits_for_the_deposit(): void
    {
        $res = $this->actingAs($this->admin)->postJson('/api/v1/reservations', $this->booking())->assertCreated();

        $res->assertJsonPath('data.code', 'BL-26-00001')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.subtotal', '4000.00')
            ->assertJsonPath('data.tax_total', '600.00') // VAT 15%
            ->assertJsonPath('data.total', '4600.00')
            ->assertJsonPath('data.deposit_required', '1380.00') // 30%
            ->assertJsonPath('data.expires_at', '2026-11-11T09:00:00+03:00') // 24 hours
            ->assertJsonPath('data.policy.name', 'Standard')
            ->assertJsonPath('data.rooms.0.lines.1.date', '2026-11-13');

        $this->postJson('/api/v1/reservations', $this->booking())->assertJsonPath('data.code', 'BL-26-00002');
    }

    public function test_deposit_confirms_the_booking(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/reservations', $this->booking())->json('data.id');

        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'deposit', 'payment_method_id' => $this->cash, 'amount' => '1000'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.balance', '3600.00');
        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'deposit', 'payment_method_id' => $this->cash, 'amount' => '380'])
            ->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.expires_at', null)->assertJsonPath('data.paid_total', '1380.00');
    }

    public function test_payment_in_another_currency_uses_the_latest_rate(): void
    {
        $this->inHotel(fn () => ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'ETB', 'rate' => '128.5', 'effective_date' => '2026-11-09']));
        $id = $this->actingAs($this->admin)->postJson('/api/v1/reservations', $this->booking())->json('data.id');

        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'deposit', 'payment_method_id' => $this->cash, 'amount' => '20', 'currency' => 'USD'])
            ->assertCreated()->assertJsonPath('data.paid_total', '2570.00')->assertJsonPath('data.payments.0.amount', '20.00');
        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'deposit', 'payment_method_id' => $this->cash, 'amount' => '20', 'currency' => 'EUR'])
            ->assertJsonValidationErrors('currency');
    }

    public function test_overbooking_is_blocked(): void
    {
        $this->actingAs($this->admin);
        $two = $this->booking(['rooms' => [['room_type_id' => $this->standard->id], ['room_type_id' => $this->standard->id]]]);
        $this->postJson('/api/v1/reservations', $two)->assertCreated();

        $this->postJson('/api/v1/reservations', $this->booking())->assertJsonValidationErrors('rooms');
        $this->postJson('/api/v1/reservations', $this->booking(['check_in' => '2026-11-13', 'check_out' => '2026-11-15']))->assertJsonValidationErrors('rooms');
        // Back to back: they leave on the 14th at 12:00, new guests arrive at 14:00.
        $this->postJson('/api/v1/reservations', $this->booking(['check_in' => '2026-11-14', 'check_out' => '2026-11-15']))->assertCreated();
    }

    public function test_two_short_bookings_on_different_nights_only_use_one_room(): void
    {
        $this->actingAs($this->admin);
        $this->postJson('/api/v1/reservations', $this->booking(['check_in' => '2026-11-12', 'check_out' => '2026-11-13']))->assertCreated();
        $this->postJson('/api/v1/reservations', $this->booking(['check_in' => '2026-11-13', 'check_out' => '2026-11-14']))->assertCreated();

        $this->postJson('/api/v1/reservations', $this->booking())->assertCreated(); // one room is still free both nights
        $this->postJson('/api/v1/reservations', $this->booking())->assertJsonValidationErrors('rooms');
    }

    public function test_a_chosen_room_number_must_be_free(): void
    {
        $this->actingAs($this->admin);
        $room = $this->inHotel(fn () => Room::where('number', '101')->first());
        $this->postJson('/api/v1/reservations', $this->booking(['rooms' => [['room_type_id' => $this->standard->id, 'room_id' => $room->id]]]))->assertCreated()
            ->assertJsonPath('data.rooms.0.room_number', '101');
        $this->postJson('/api/v1/reservations', $this->booking(['rooms' => [['room_type_id' => $this->standard->id, 'room_id' => $room->id]]]))
            ->assertJsonValidationErrors('rooms.0.room_id');
    }

    public function test_hourly_stays_follow_minimum_hours_and_cleaning_time(): void
    {
        $this->actingAs($this->admin);
        $hourly = fn (string $start, int $hours) => $this->booking([
            'stay_type' => 'hourly', 'check_in' => $start, 'check_out' => null, 'hours' => $hours,
            'rooms' => [['room_type_id' => $this->deluxe->id]],
        ]);

        $this->postJson('/api/v1/reservations', $hourly('2026-11-10T13:00', 1))->assertJsonValidationErrors('hours'); // minimum 2
        $this->postJson('/api/v1/reservations', $hourly('2026-11-10T13:00', 3))->assertCreated()
            ->assertJsonPath('data.subtotal', '900.00')->assertJsonPath('data.total', '1035.00')
            ->assertJsonPath('data.check_out_at', '2026-11-10T16:00:00+03:00');

        $this->postJson('/api/v1/reservations', $hourly('2026-11-10T16:00', 2))->assertJsonValidationErrors('rooms'); // cleaning until 16:30
        $this->postJson('/api/v1/reservations', $hourly('2026-11-10T16:30', 2))->assertCreated();
    }

    public function test_day_use_needs_a_day_use_price(): void
    {
        $this->actingAs($this->admin);
        $dayUse = fn (int $typeId) => $this->booking(['stay_type' => 'day_use', 'check_in' => '2026-11-11T09:00', 'check_out' => '2026-11-11T17:00', 'rooms' => [['room_type_id' => $typeId]]]);

        $this->postJson('/api/v1/reservations', $dayUse($this->standard->id))->assertJsonValidationErrors('rooms');
        $this->postJson('/api/v1/reservations', $dayUse($this->deluxe->id))->assertCreated()->assertJsonPath('data.subtotal', '1200.00');
    }

    public function test_discount_is_capped_by_the_role(): void
    {
        $receptionist = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $receptionist->branches()->attach($this->branch->id);
        $this->actingAs($receptionist);

        $this->postJson('/api/v1/reservations', $this->booking(['discount_percent' => '10']))->assertJsonValidationErrors('discount_percent');
        $this->postJson('/api/v1/reservations', $this->booking(['discount_percent' => '5']))->assertCreated()
            ->assertJsonPath('data.discount_amount', '200.00')->assertJsonPath('data.total', '4370.00'); // (4000 − 200) × 1.15
    }

    public function test_cancel_is_free_early_and_costs_a_night_late(): void
    {
        $this->actingAs($this->admin);
        $id = $this->postJson('/api/v1/reservations', $this->booking(['check_in' => '2026-11-20', 'check_out' => '2026-11-22']))->json('data.id');
        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'deposit', 'payment_method_id' => $this->cash, 'amount' => '1380']);

        $this->getJson("/api/v1/reservations/$id/cancellation-terms")->assertJsonPath('data.free', true)->assertJsonPath('data.refund_due', '1380.00');

        $this->travelTo(Carbon::parse('2026-11-19 10:00', 'Africa/Addis_Ababa')); // 28 h before: inside 48 h
        $this->getJson("/api/v1/reservations/$id/cancellation-terms")->assertJsonPath('data.free', false)
            ->assertJsonPath('data.fee', '2000.00')->assertJsonPath('data.refund_due', '0.00');

        $this->postJson("/api/v1/reservations/$id/cancel", ['reason' => 'Plans changed'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancellation_fee', '2000.00');
        // Rooms are free again.
        $this->postJson('/api/v1/reservations', $this->booking(['check_in' => '2026-11-20', 'check_out' => '2026-11-22', 'rooms' => [['room_type_id' => $this->standard->id], ['room_type_id' => $this->standard->id]]]))->assertCreated();
    }

    public function test_a_repeated_payment_is_saved_once(): void
    {
        $this->actingAs($this->admin);
        $id = $this->postJson('/api/v1/reservations', $this->booking(['check_in' => '2026-11-20', 'check_out' => '2026-11-22']))->json('data.id');
        $pay = fn (string $key) => $this->withHeader('Idempotency-Key', $key)->postJson("/api/v1/reservations/$id/payments", ['type' => 'deposit', 'payment_method_id' => $this->cash, 'amount' => '500']);

        $pay('abc-1')->assertOk()->assertJsonPath('data.paid_total', '500.00');
        $pay('abc-1')->assertOk()->assertHeader('Idempotent-Replay', 'true')->assertJsonPath('data.paid_total', '500.00'); // the internet dropped, the app tried again
        $pay('abc-2')->assertOk()->assertJsonPath('data.paid_total', '1000.00'); // a new, real payment
        $this->assertSame(2, $this->inHotel(fn () => Payment::where('reservation_id', $id)->count()));
    }

    public function test_changes_to_one_booking_run_one_at_a_time(): void
    {
        $this->actingAs($this->admin);
        $id = $this->postJson('/api/v1/reservations', $this->booking(['check_in' => '2026-11-20', 'check_out' => '2026-11-22']))->json('data.id');

        // Another change to the same booking is still running (e.g. a double tap).
        $lock = Cache::lock("one-at-a-time:reservation:{$this->hotel->id}:{$id}", 30);
        $this->assertTrue($lock->get());
        $this->postJson("/api/v1/reservations/$id/cancel", ['reason' => 'Twice'])->assertStatus(409)->assertJsonPath('code', 'busy');
        $lock->release();

        $this->postJson("/api/v1/reservations/$id/cancel", ['reason' => 'Once'])->assertOk();
        $this->postJson("/api/v1/reservations/$id/cancel", ['reason' => 'Again'])->assertUnprocessable(); // already cancelled
    }

    public function test_rooms_types_and_branches_in_use_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin);
        $room = $this->inHotel(fn () => Room::where('number', '201')->first());
        $id = $this->postJson('/api/v1/reservations', $this->booking(['rooms' => [['room_type_id' => $this->deluxe->id, 'room_id' => $room->id]]]))->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/settings/rooms/{$room->id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/settings/room-types/{$this->deluxe->id}")->assertUnprocessable();
        $second = Branch::factory()->for($this->hotel)->create(['name' => 'Piassa', 'code' => 'PS']);
        $this->deleteJson("/api/v1/settings/branches/{$this->branch->id}")->assertUnprocessable();

        $this->postJson("/api/v1/reservations/$id/cancel", ['reason' => 'Test'])->assertOk();
        $this->deleteJson("/api/v1/settings/rooms/{$room->id}")->assertOk();
        $this->assertNotNull($second);
    }

    public function test_no_show_only_after_check_in_time(): void
    {
        $this->actingAs($this->admin);
        $id = $this->postJson('/api/v1/reservations', $this->booking())->json('data.id');

        $this->postJson("/api/v1/reservations/$id/no-show")->assertJsonValidationErrors('status');
        $this->travelTo(Carbon::parse('2026-11-12 20:00', 'Africa/Addis_Ababa'));
        $this->postJson("/api/v1/reservations/$id/no-show")->assertOk()->assertJsonPath('data.status', 'no_show')->assertJsonPath('data.cancellation_fee', '2000.00');
    }

    public function test_unpaid_pending_bookings_expire(): void
    {
        $this->actingAs($this->admin);
        $id = $this->postJson('/api/v1/reservations', $this->booking())->json('data.id');

        $this->travel(25)->hours();
        $this->artisan('reservations:expire')->assertSuccessful();

        $r = $this->inHotel(fn () => Reservation::find($id));
        $this->assertSame('cancelled', $r->status);
        $this->assertSame('expired', $r->cancel_reason);
        $this->assertSame('0.00', (string) $r->cancellation_fee);
    }

    public function test_editing_recalculates_the_price_and_keeps_the_deposit(): void
    {
        $this->actingAs($this->admin);
        $id = $this->postJson('/api/v1/reservations', $this->booking(['rooms' => [['room_type_id' => $this->standard->id], ['room_type_id' => $this->standard->id]]]))->json('data.id');
        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'deposit', 'payment_method_id' => $this->cash, 'amount' => '2760'])->assertJsonPath('data.status', 'confirmed');

        // The booking's own rooms do not block the change.
        $this->putJson("/api/v1/reservations/$id", $this->booking(['check_out' => '2026-11-15', 'rooms' => [['room_type_id' => $this->standard->id], ['room_type_id' => $this->standard->id]]]))
            ->assertOk()->assertJsonPath('data.subtotal', '12000.00')->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.paid_total', '2760.00')->assertJsonPath('data.deposit_required', '2760.00');
    }

    public function test_blacklisted_guests_cannot_book(): void
    {
        $this->actingAs($this->admin)->postJson("/api/v1/guests/{$this->guest->id}/blacklist", ['is_blacklisted' => true, 'reason' => 'Unpaid bill'])->assertOk();
        $this->postJson('/api/v1/reservations', $this->booking())->assertJsonValidationErrors('guest_id');
    }

    public function test_refunds_need_permission_and_cannot_exceed_payments(): void
    {
        $this->actingAs($this->admin);
        $id = $this->postJson('/api/v1/reservations', $this->booking())->json('data.id');
        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'deposit', 'payment_method_id' => $this->cash, 'amount' => '500']);

        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'refund', 'payment_method_id' => $this->cash, 'amount' => '600', 'refund_reason' => 'x'])->assertJsonValidationErrors('amount');
        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'refund', 'payment_method_id' => $this->cash, 'amount' => '200'])->assertJsonValidationErrors('refund_reason');

        $receptionist = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $receptionist->branches()->attach($this->branch->id);
        $this->actingAs($receptionist)->postJson("/api/v1/reservations/$id/payments", ['type' => 'refund', 'payment_method_id' => $this->cash, 'amount' => '100', 'refund_reason' => 'x'])->assertForbidden();
    }

    public function test_guests_search_and_profile(): void
    {
        $this->actingAs($this->admin);
        $this->postJson('/api/v1/guests', ['first_name' => 'Sara', 'phone' => '0922 334455', 'id_type' => 'passport', 'id_number' => 'EP123'])->assertCreated();
        $this->postJson('/api/v1/guests', ['first_name' => 'Sara B', 'phone' => '0922 334455'])->assertJsonPath('possible_duplicate.name', 'Sara');

        $this->getJson('/api/v1/guests?q=223344')->assertJsonPath('total', 1)->assertJsonPath('data.0.full_name', 'Abebe Kebede');
        $this->getJson('/api/v1/guests?q=Abebe Keb')->assertJsonPath('total', 1);
        $this->getJson('/api/v1/guests?q=EP123')->assertJsonPath('data.0.first_name', 'Sara');

        $this->postJson('/api/v1/reservations', $this->booking())->assertCreated();
        $this->getJson("/api/v1/guests/{$this->guest->id}")->assertJsonPath('stays.0.code', 'BL-26-00001');
    }

    public function test_calendar_lists_rooms_and_bookings(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/reservations', $this->booking())->assertCreated();

        $this->getJson("/api/v1/reservations/calendar?branch_id={$this->branch->id}&from=2026-11-10&days=7")->assertOk()
            ->assertJsonCount(3, 'rooms')->assertJsonCount(1, 'bookings')
            ->assertJsonPath('bookings.0.guest', 'Abebe Kebede')->assertJsonPath('bookings.0.room_id', null);
    }

    public function test_bookings_and_guests_of_another_hotel_are_invisible(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/reservations', $this->booking())->json('data.id');

        $other = Hotel::factory()->create(['plan_id' => $this->hotel->plan_id]);
        Branch::factory()->for($other)->create();
        app(HotelDefaults::class)->apply($other);
        $stranger = User::factory()->withRole($other, 'hotel_admin')->create();

        $this->actingAs($stranger)->getJson("/api/v1/reservations/$id")->assertNotFound();
        $this->getJson('/api/v1/reservations')->assertJsonPath('total', 0);
        $this->getJson("/api/v1/guests/{$this->guest->id}")->assertNotFound();
        $this->postJson("/api/v1/reservations/$id/cancel", ['reason' => 'x'])->assertNotFound();
        // Cannot book the other hotel's guest or room type either.
        $this->postJson('/api/v1/reservations', $this->booking(['branch_id' => $other->branches()->first()->id]))->assertJsonValidationErrors(['guest_id']);
    }
}
