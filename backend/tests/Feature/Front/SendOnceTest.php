<?php

namespace Tests\Feature\Front;

use App\Models\Branch;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Billing\InvoiceService;
use App\Services\HotelDefaults;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Owner (QA 8 Nov): every action that takes money or creates / changes data is saved once. The app sends one
 * Idempotency-Key per submission and the same key for its retries; the server keeps key + user + endpoint +
 * request hash + answer (table idempotency_keys) and answers a repeat with the first result.
 */
class SendOnceTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private Branch $branch;

    private User $receptionist;

    private RoomType $standard;

    private Guest $guest;

    private int $telebirr;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hotelms.idempotency_wait_ms' => 0]); // tests do not wait for a "busy" key
        $this->travelTo(Carbon::parse('2026-11-12 09:00', 'Africa/Addis_Ababa'));
        $this->hotel = Hotel::factory()->create(['plan_id' => Plan::factory()->create(['max_rooms' => null, 'modules' => Plan::MODULES])->id]);
        $this->branch = Branch::factory()->for($this->hotel)->create(['name' => 'Bole', 'check_in_time' => '14:00', 'check_out_time' => '12:00']);
        app(HotelDefaults::class)->apply($this->hotel);
        $this->receptionist = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $this->receptionist->branches()->attach($this->branch->id);
        $this->inHotel(function () {
            $this->standard = RoomType::create(['branch_id' => $this->branch->id, 'name' => 'Standard', 'base_capacity' => 2, 'max_capacity' => 2, 'nightly_price' => '2000', 'currency' => 'ETB']);
            Room::create(['branch_id' => $this->branch->id, 'room_type_id' => $this->standard->id, 'number' => '101', 'status' => 'available']);
            $this->guest = Guest::create(['first_name' => 'Abebe']);
            $this->telebirr = PaymentMethod::where('name', 'telebirr')->value('id');
        });
        $this->actingAs($this->receptionist);
    }

    private function inHotel(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->hotel, $fn);
    }

    private function booking(string $key = 'booking-1'): int
    {
        return $this->postJson('/api/v1/reservations', [
            'branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2026-11-20', 'check_out' => '2026-11-21',
            'guest_id' => $this->guest->id, 'rooms' => [['room_type_id' => $this->standard->id]],
        ], ['Idempotency-Key' => $key])->assertCreated()->json('data.id');
    }

    private function pay(int $id, string $key, string $amount = '500'): TestResponse
    {
        return $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'deposit', 'payment_method_id' => $this->telebirr, 'amount' => $amount], ['Idempotency-Key' => $key]);
    }

    private function payments(): int
    {
        return $this->inHotel(fn () => Payment::count());
    }

    public function test_double_click_on_a_payment_takes_the_money_once(): void
    {
        $id = $this->booking();
        $first = $this->pay($id, 'pay-1')->assertCreated();
        $second = $this->pay($id, 'pay-1')->assertCreated()->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, $this->payments());
        $this->assertSame(1, DB::table('idempotency_keys')->where('key', 'pay-1')->where('status', 'done')->count());
    }

    public function test_a_retry_after_a_slow_or_timed_out_request_gets_the_first_answer(): void
    {
        $id = $this->booking();
        $first = $this->pay($id, 'pay-slow')->assertCreated();
        $this->travel(6)->seconds();   // slow network: the app gave up waiting and sends again
        $this->pay($id, 'pay-slow')->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->travel(3)->hours();     // a resend much later (a device that was offline) – still the same submission
        $this->pay($id, 'pay-slow')->assertCreated()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame(1, $this->payments());
    }

    public function test_the_same_key_with_different_details_is_refused(): void
    {
        $id = $this->booking();
        $this->pay($id, 'pay-x', '500')->assertCreated();
        $this->pay($id, 'pay-x', '900')->assertStatus(422)->assertJsonPath('code', 'idempotency_mismatch');
        $this->postJson('/api/v1/guests', ['first_name' => 'Other'], ['Idempotency-Key' => 'pay-x'])->assertStatus(422)->assertJsonPath('code', 'idempotency_mismatch');
        $this->assertSame(1, $this->payments());
    }

    public function test_two_requests_with_the_same_key_at_the_same_moment_save_once(): void
    {
        $id = $this->booking();
        // The first request is still running (its row says "processing") when the second arrives.
        DB::table('idempotency_keys')->insert([
            'user_id' => $this->receptionist->id, 'key' => 'pay-race', 'endpoint' => "POST api/v1/reservations/$id/payments",
            'request_hash' => hash('sha256', json_encode(['type' => 'deposit', 'payment_method_id' => $this->telebirr, 'amount' => '500']).'|'),
            'status' => 'processing', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->pay($id, 'pay-race')->assertStatus(409)->assertJsonPath('code', 'busy');
        $this->assertSame(0, $this->payments());

        // And the database itself never holds the same key twice for a person.
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('idempotency_keys')->insert(['user_id' => $this->receptionist->id, 'key' => 'pay-race', 'endpoint' => 'x', 'request_hash' => str_repeat('0', 64), 'status' => 'processing', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_failed_attempt_frees_the_key_and_a_new_key_is_a_new_action(): void
    {
        $id = $this->booking();
        $this->pay($id, 'pay-fail', 'abc')->assertStatus(422); // a typing mistake: nothing saved, key not kept
        $this->assertSame(0, DB::table('idempotency_keys')->where('key', 'pay-fail')->count());

        $this->pay($id, 'pay-a')->assertCreated();
        $this->pay($id, 'pay-b')->assertCreated(); // a real second payment
        $this->assertSame(2, $this->payments());
    }

    public function test_a_key_older_than_a_day_no_longer_counts(): void
    {
        $id = $this->booking();
        $this->pay($id, 'pay-old')->assertCreated();
        $this->travel(25)->hours();
        $this->pay($id, 'pay-old')->assertCreated()->assertHeaderMissing('Idempotent-Replay');
        $this->assertSame(2, $this->payments());
    }

    public function test_every_listed_action_is_saved_once(): void
    {
        // New booking and new guest.
        $before = $this->inHotel(fn () => Reservation::count());
        $this->booking('b-twice');
        $this->booking('b-twice');
        $this->assertSame($before + 1, $this->inHotel(fn () => Reservation::count()));

        $guests = $this->inHotel(fn () => Guest::count());
        foreach ([1, 2] as $n) {
            $this->postJson('/api/v1/guests', ['first_name' => 'Double'], ['Idempotency-Key' => 'g-twice'])->assertCreated();
        }
        $this->assertSame($guests + 1, $this->inHotel(fn () => Guest::count()));

        // A bill charge.
        $id = $this->booking('b-bill');
        $invoice = $this->inHotel(fn () => app(InvoiceService::class)->openFor(Reservation::find($id), $this->receptionist)->id);
        $items = fn () => $this->inHotel(fn () => Invoice::find($invoice)->items()->count());
        $start = $items();
        foreach ([1, 2] as $n) {
            $this->postJson("/api/v1/invoices/$invoice/items", ['type' => 'extra', 'description' => 'Laundry', 'quantity' => '1', 'unit_price' => '150'], ['Idempotency-Key' => 'item-twice'])->assertCreated();
        }
        $this->assertSame($start + 1, $items());

        // Cashier shift open / close, cancel.
        foreach ([1, 2] as $n) {
            $this->postJson('/api/v1/cashier/open', ['opening' => ['ETB' => '500']], ['Idempotency-Key' => 'open-twice'])->assertCreated();
        }
        foreach ([1, 2] as $n) {
            $this->postJson('/api/v1/cashier/close', ['counted' => ['ETB' => '500']], ['Idempotency-Key' => 'close-twice'])->assertOk()->assertJsonPath('data.status', 'closed');
        }
        foreach ([1, 2] as $n) {
            $this->postJson("/api/v1/reservations/$id/cancel", ['reason' => 'Plans changed'], ['Idempotency-Key' => 'cancel-twice'])->assertOk();
        }
        $this->assertSame('cancelled', $this->inHotel(fn () => Reservation::find($id)->status));
    }
}
