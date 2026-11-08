<?php

namespace Tests\Feature\Front;

use App\Models\Branch;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Billing\InvoiceService;
use App\Services\HotelDefaults;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Owner (QA 8 Nov): every action that takes money or creates / changes data is saved once, also when it arrives
 * twice (double-click, slow network, second tab). The app sends the same Idempotency-Key for a repeat; the server
 * answers with the first result and saves nothing new.
 */
class SendOnceTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private Branch $branch;

    private User $receptionist;

    private RoomType $standard;

    private Guest $guest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-12 09:00', 'Africa/Addis_Ababa'));
        $this->hotel = Hotel::factory()->create(['plan_id' => Plan::factory()->create(['max_rooms' => null, 'modules' => Plan::MODULES])->id]);
        $this->branch = Branch::factory()->for($this->hotel)->create(['name' => 'Bole', 'check_in_time' => '14:00', 'check_out_time' => '12:00']);
        app(HotelDefaults::class)->apply($this->hotel);
        $this->receptionist = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $this->receptionist->branches()->attach($this->branch->id);
        app(TenantContext::class)->runAs($this->hotel, function () {
            $this->standard = RoomType::create(['branch_id' => $this->branch->id, 'name' => 'Standard', 'base_capacity' => 2, 'max_capacity' => 2, 'nightly_price' => '2000', 'currency' => 'ETB']);
            Room::create(['branch_id' => $this->branch->id, 'room_type_id' => $this->standard->id, 'number' => '101', 'status' => 'available']);
            $this->guest = Guest::create(['first_name' => 'Abebe']);
        });
        $this->actingAs($this->receptionist);
    }

    private function rows(string $model): int
    {
        return app(TenantContext::class)->runAs($this->hotel, fn () => $model::count());
    }

    /** @param  array<string, mixed>  $data */
    private function twice(string $url, array $data, string $key): array
    {
        $first = $this->postJson($url, $data, ['Idempotency-Key' => $key]);
        $second = $this->postJson($url, $data, ['Idempotency-Key' => $key]);
        $this->assertSame($first->status(), $second->status());
        $this->assertSame($first->json(), $second->json());
        $second->assertHeader('Idempotent-Replay', 'true');

        return [$first, $second];
    }

    public function test_a_new_guest_sent_twice_is_saved_once(): void
    {
        $before = $this->rows(Guest::class);
        $this->twice('/api/v1/guests', ['first_name' => 'Double', 'last_name' => 'Click', 'phone' => '+251911000999'], 'k-guest');
        $this->assertSame($before + 1, $this->rows(Guest::class));
    }

    public function test_a_new_booking_sent_twice_is_saved_once_and_cancelled_once(): void
    {
        $data = ['branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2026-11-20', 'check_out' => '2026-11-21',
            'guest_id' => $this->guest->id, 'rooms' => [['room_type_id' => $this->standard->id]]];
        [$first] = $this->twice('/api/v1/reservations', $data, 'k-booking');
        $this->assertSame(1, $this->rows(Reservation::class));

        $id = $first->json('data.id');
        $this->twice("/api/v1/reservations/$id/cancel", ['reason' => 'Plans changed'], 'k-cancel');
        $this->assertSame('cancelled', app(TenantContext::class)->runAs($this->hotel, fn () => Reservation::find($id)->status));
    }

    public function test_a_bill_charge_sent_twice_is_added_once(): void
    {
        $id = $this->postJson('/api/v1/reservations', ['branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2026-11-12', 'check_out' => '2026-11-13',
            'guest_id' => $this->guest->id, 'rooms' => [['room_type_id' => $this->standard->id]]])->assertCreated()->json('data.id');
        // The bill is opened the first time something is charged or paid (check-in, payment…).
        $invoice = app(TenantContext::class)->runAs($this->hotel, fn () => app(InvoiceService::class)->openFor(Reservation::find($id), $this->receptionist)->id);
        $this->assertNotNull($invoice);
        $items = fn () => app(TenantContext::class)->runAs($this->hotel, fn () => Invoice::find($invoice)->items()->count());
        $before = $items();
        $this->twice("/api/v1/invoices/$invoice/items", ['type' => 'extra', 'description' => 'Laundry', 'quantity' => '1', 'unit_price' => '150'], 'k-item');
        $this->assertSame($before + 1, $items());
    }

    public function test_opening_and_closing_the_cashier_shift_twice_does_it_once(): void
    {
        $this->twice('/api/v1/cashier/open', ['opening' => ['ETB' => '500']], 'k-open')[0]->assertCreated();
        $this->twice('/api/v1/cashier/close', ['counted' => ['ETB' => '500']], 'k-close')[0]->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_a_different_key_is_a_new_change(): void
    {
        $before = $this->rows(Guest::class);
        $this->postJson('/api/v1/guests', ['first_name' => 'Two'], ['Idempotency-Key' => 'a'])->assertCreated();
        $this->postJson('/api/v1/guests', ['first_name' => 'Two'], ['Idempotency-Key' => 'b'])->assertCreated();
        $this->assertSame($before + 2, $this->rows(Guest::class));
    }
}
