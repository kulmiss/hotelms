<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\PlatformSetting;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\DemoHotel;
use App\Services\Reports\Metrics;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoHotelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlatformSeeder::class);
    }

    public function test_demo_has_two_branches_and_one_person_per_role(): void
    {
        $hotel = app(DemoHotel::class)->reset();

        $this->assertTrue($hotel->is_demo);
        $this->assertSame('active', $hotel->status);
        $this->assertSame(2, app(TenantContext::class)->runAs($hotel, fn () => Branch::count()));
        foreach (array_keys(DemoHotel::PEOPLE) as $role) {
            $user = User::where('email', DemoHotel::email($role))->firstOrFail();
            $this->assertSame($role, $user->role->key);
            $this->assertFalse($user->must_change_password);
        }
    }

    public function test_demo_has_history_guests_in_house_and_bills(): void
    {
        $hotel = app(DemoHotel::class)->reset();
        app(TenantContext::class)->runAs($hotel, function () {
            $this->assertSame(60, Room::count());
            $this->assertGreaterThan(50, Reservation::where('status', 'checked_out')->count());
            $this->assertGreaterThan(0, Reservation::where('status', 'checked_in')->count());
            $this->assertGreaterThan(10, Reservation::whereIn('status', ['pending', 'confirmed'])->count());
            $this->assertGreaterThan(50, Invoice::where('status', 'paid')->count());
            $this->assertTrue(Reservation::where('status', 'checked_out')->where('check_in_at', '<', now()->subMonths(3))->exists());
        });
    }

    public function test_reset_wipes_visitors_changes(): void
    {
        $demo = app(DemoHotel::class);
        $hotel = $demo->reset();
        $hotel->update(['name' => 'Changed by a visitor']);

        $fresh = $demo->reset();

        $this->assertSame('Sheger Demo Hotel', $fresh->name);
        $this->assertSame(1, Hotel::where('is_demo', true)->count());
    }

    public function test_try_the_demo_only_works_when_switched_on(): void
    {
        app(DemoHotel::class)->reset();

        $this->postJson('/api/v1/demo/login', ['role' => 'receptionist'])->assertNotFound();

        PlatformSetting::write('demo_public', true);
        $this->postJson('/api/v1/demo/login', ['role' => 'receptionist'])->assertOk();
        $this->getJson('/api/v1/me')->assertJsonPath('user.email', DemoHotel::email('receptionist'));
    }

    public function test_the_nightly_command_rebuilds_the_demo(): void
    {
        $this->artisan('demo:reset')->assertSuccessful();

        $this->assertSame(1, Hotel::where('is_demo', true)->count());
    }

    public function test_the_demo_is_busy_consistent_and_fast(): void
    {
        $started = microtime(true);
        $hotel = app(DemoHotel::class)->reset();
        $this->assertLessThan(60, microtime(true) - $started, 'The demo reset must stay quick.');

        app(TenantContext::class)->runAs($hotel, function () use ($hotel) {
            // About 70% full over the last 30 days (a year of history behind it).
            $from = now('Africa/Addis_Ababa')->subDays(30)->toDateString();
            $to = now('Africa/Addis_Ababa')->subDay()->toDateString();
            $kpis = Metrics::for($hotel, Branch::pluck('id')->all())->kpis($from, $to);
            $this->assertGreaterThan(45, $kpis['occupancy']);
            $this->assertTrue(Reservation::where('check_in_at', '<', now()->subMonths(11))->exists());

            // Today: guests in house in occupied rooms, people arriving, people leaving.
            $start = now('Africa/Addis_Ababa')->startOfDay()->utc();
            $this->assertGreaterThan(20, Reservation::where('status', 'checked_in')->count());
            $this->assertGreaterThan(0, Reservation::whereIn('status', ['confirmed', 'pending'])->whereBetween('check_in_at', [$start, $start->copy()->addDay()])->count());
            $this->assertGreaterThan(0, Reservation::where('status', 'checked_in')->whereBetween('check_out_at', [$start, $start->copy()->addDay()])->count());
            $this->assertSame(
                DB::table('reservation_rooms')->where('hotel_id', $hotel->id)->where('status', 'checked_in')->distinct()->count('room_id'),
                Room::where('status', 'occupied')->count(),
            );

            // Busy but not full: there are clean free rooms for a walk-in, and every dirty room has a cleaning task.
            $this->assertLessThan(95, $kpis['occupancy']);
            $this->assertGreaterThan(0, Room::where('status', 'available')->count());
            $dirty = Room::where('status', 'dirty')->pluck('id');
            $this->assertNotEmpty($dirty);
            $this->assertSame($dirty->count(), DB::table('housekeeping_tasks')->whereIn('room_id', $dirty)->where('status', '!=', 'done')->distinct()->count('room_id'));

            // Future bookings were made over the last weeks, not all today; most already have a room.
            $this->assertLessThan(60, Reservation::where('created_at', '>=', $start)->count());
            $future = DB::table('reservation_rooms')->where('hotel_id', $hotel->id)->where('status', 'confirmed')->where('check_in_at', '>', $start->copy()->addDays(2));
            $this->assertGreaterThan((clone $future)->count() / 2, (clone $future)->whereNotNull('room_id')->count());

            // The team: a rota, attendance, and cash held in cashier shifts (one open shift per person).
            $this->assertGreaterThan(0, DB::table('shift_assignments')->where('hotel_id', $hotel->id)->where('date', '>', $start->toDateString())->count());
            $this->assertGreaterThan(0, DB::table('attendances')->where('hotel_id', $hotel->id)->count());
            $this->assertSame(0, DB::table('payments as p')->join('payment_methods as m', 'm.id', '=', 'p.payment_method_id')
                ->where('p.hotel_id', $hotel->id)->where('m.type', 'cash')->where('p.paid_at', '>=', $start->copy()->subDays(13))->whereNull('p.cashier_session_id')->count());
            $open = DB::table('cashier_sessions')->where('hotel_id', $hotel->id)->where('status', 'open')->pluck('user_id');
            $this->assertSame($open->count(), $open->unique()->count());

            // Every bill adds up: lines + tax = total; payments = paid.
            foreach (Invoice::inRandomOrder()->limit(25)->get() as $invoice) {
                $lines = (string) DB::table('invoice_items')->where('invoice_id', $invoice->id)->sum('amount');
                $tax = (string) DB::table('invoice_item_taxes')->join('invoice_items', 'invoice_items.id', '=', 'invoice_item_taxes.invoice_item_id')
                    ->where('invoice_items.invoice_id', $invoice->id)->where('invoice_item_taxes.is_inclusive', false)->sum('invoice_item_taxes.amount');
                $this->assertSame(Money::round((string) $invoice->total), Money::round(bcadd($lines, $tax, 2)), "Bill {$invoice->number}");
                $paid = (string) DB::table('payments')->where('invoice_id', $invoice->id)->sum('amount_in_base');
                $this->assertSame(Money::round((string) $invoice->paid_total), Money::round($paid), "Bill {$invoice->number}");
            }
        });

        // New bookings continue the numbers (no clash with the history).
        $admin = User::where('email', DemoHotel::email('hotel_admin'))->firstOrFail();
        $branch = app(TenantContext::class)->runAs($hotel, fn () => Branch::orderBy('id')->first());
        $type = app(TenantContext::class)->runAs($hotel, fn () => RoomType::where('branch_id', $branch->id)->where('name', 'Suite')->first());
        $guest = app(TenantContext::class)->runAs($hotel, fn () => Guest::first());
        $in = now('Africa/Addis_Ababa')->addDays(70)->toDateString();
        $this->actingAs($admin)->postJson('/api/v1/reservations', [
            'branch_id' => $branch->id, 'stay_type' => 'nightly', 'check_in' => $in, 'check_out' => now('Africa/Addis_Ababa')->addDays(71)->toDateString(),
            'guest_id' => $guest->id, 'rooms' => [['room_type_id' => $type->id, 'adults' => 2]],
        ])->assertCreated();
        $this->getJson('/api/v1/reports/performance?from='.now()->subDays(90)->toDateString().'&to='.now()->toDateString())->assertOk();
    }
}
