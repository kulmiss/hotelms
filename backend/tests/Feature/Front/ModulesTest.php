<?php

namespace Tests\Feature\Front;

use App\Models\Branch;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\HotelModule;
use App\Models\HousekeepingTask;
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

class ModulesTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private Branch $branch;

    private User $admin;

    private User $manager;

    private User $receptionist;

    private User $housekeeper;

    private RoomType $standard;

    private Guest $guest;

    private int $cash;

    private int $telebirr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-12 09:00', 'Africa/Addis_Ababa'));
        $this->hotel = Hotel::factory()->create(['plan_id' => Plan::factory()->create(['max_rooms' => null, 'modules' => Plan::MODULES])->id]);
        $this->branch = Branch::factory()->for($this->hotel)->create(['name' => 'Bole', 'check_in_time' => '14:00', 'check_out_time' => '12:00']);
        app(HotelDefaults::class)->apply($this->hotel);

        $this->admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();
        foreach (['manager' => 'branch_manager', 'receptionist' => 'receptionist', 'housekeeper' => 'housekeeping'] as $prop => $role) {
            $this->{$prop} = User::factory()->withRole($this->hotel, $role)->create();
            $this->{$prop}->branches()->attach($this->branch->id);
        }

        $this->inHotel(function () {
            $this->standard = RoomType::create(['branch_id' => $this->branch->id, 'name' => 'Standard', 'base_capacity' => 2, 'max_capacity' => 2, 'nightly_price' => '2000', 'currency' => 'ETB']);
            foreach (['101', '102'] as $n) {
                Room::create(['branch_id' => $this->branch->id, 'room_type_id' => $this->standard->id, 'number' => $n, 'status' => 'available']);
            }
            $this->guest = Guest::create(['first_name' => 'Abebe']);
            $this->cash = PaymentMethod::where('name', 'Cash')->value('id');
            $this->telebirr = PaymentMethod::where('name', 'telebirr')->value('id');
        });
    }

    private function inHotel(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->hotel, $fn);
    }

    private function booking(): int
    {
        return $this->postJson('/api/v1/reservations', [
            'branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2026-11-12', 'check_out' => '2026-11-13',
            'guest_id' => $this->guest->id, 'rooms' => [['room_type_id' => $this->standard->id]],
        ])->assertCreated()->json('data.id');
    }

    public function test_cash_needs_an_open_shift_and_the_count_is_checked(): void
    {
        $this->actingAs($this->receptionist);
        $id = $this->booking();

        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'deposit', 'payment_method_id' => $this->cash, 'amount' => '690'])->assertJsonValidationErrors('payment_method_id');
        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'deposit', 'payment_method_id' => $this->telebirr, 'amount' => '100'])->assertCreated(); // not cash: no shift needed

        $this->postJson('/api/v1/cashier/open', ['opening' => ['ETB' => '500']])->assertCreated();
        $this->postJson('/api/v1/cashier/open')->assertJsonValidationErrors('shift');
        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'deposit', 'payment_method_id' => $this->cash, 'amount' => '1380'])->assertCreated();

        $this->getJson('/api/v1/cashier/current')->assertJsonPath('data.expected.ETB.expected', '1880.00')->assertJsonCount(1, 'data.payments');

        $this->postJson('/api/v1/cashier/close', ['counted' => []])->assertJsonPath('errors.counted.0', 'Type the cash you counted in the drawer.');
        $this->postJson('/api/v1/cashier/close', ['counted' => ['ETB' => 'abc']])->assertJsonValidationErrors(['counted.ETB' => 'Type the amount in numbers, like 1500 or 1500.50.']);
        $this->postJson('/api/v1/cashier/close', ['counted' => ['ETB' => '1800']])->assertJsonValidationErrors('notes');
        $closed = $this->postJson('/api/v1/cashier/close', ['counted' => ['ETB' => '1800'], 'notes' => 'Gave wrong change'])->assertOk()
            ->assertJsonPath('data.status', 'closed')->assertJsonPath('data.balances.0.difference', '-80.00');
        $sessionId = $closed->json('data.id');

        $this->postJson("/api/v1/cashier/sessions/$sessionId/approve")->assertForbidden();
        $this->actingAs($this->manager)->getJson('/api/v1/cashier/sessions?short=1')->assertJsonPath('total', 1);
        $this->postJson("/api/v1/cashier/sessions/$sessionId/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_cash_is_free_when_the_module_is_off(): void
    {
        $this->inHotel(fn () => HotelModule::where('module', 'cashier_close')->update(['is_enabled' => false]));
        $this->actingAs($this->receptionist);
        $id = $this->booking();

        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'deposit', 'payment_method_id' => $this->cash, 'amount' => '690'])->assertCreated();
        $this->getJson('/api/v1/cashier/current')->assertForbidden()->assertJsonPath('code', 'module_off');
    }

    public function test_check_out_makes_a_cleaning_task_that_housekeeping_completes(): void
    {
        $this->inHotel(fn () => HotelModule::where('module', 'cashier_close')->update(['is_enabled' => false]));
        $this->actingAs($this->admin);
        $id = $this->booking();
        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'payment', 'payment_method_id' => $this->cash, 'amount' => '2300']);
        $this->travelTo(Carbon::parse('2026-11-12 15:00', 'Africa/Addis_Ababa'));
        $stay = $this->getJson("/api/v1/reservations/$id")->json('data.rooms.0.id');
        $room = $this->inHotel(fn () => Room::where('number', '101')->first());
        $this->postJson("/api/v1/reservations/$id/check-in", ['rooms' => [['id' => $stay, 'room_id' => $room->id]]])->assertOk();
        $this->travelTo(Carbon::parse('2026-11-13 11:00', 'Africa/Addis_Ababa'));
        $this->postJson("/api/v1/reservations/$id/check-out")->assertOk();

        $task = $this->actingAs($this->housekeeper)->getJson('/api/v1/housekeeping/tasks')->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame('dirty', $task['room']['status']);

        $this->postJson("/api/v1/housekeeping/tasks/{$task['id']}/start")->assertOk()->assertJsonPath('data.room.status', 'cleaning');
        $this->postJson("/api/v1/housekeeping/tasks/{$task['id']}/done", ['notes' => 'Towels replaced'])->assertOk()
            ->assertJsonPath('data.status', 'done')->assertJsonPath('data.room.status', 'available');
    }

    public function test_a_room_marked_dirty_gets_one_cleaning_task_for_housekeeping(): void
    {
        $room = $this->inHotel(fn () => Room::where('number', '102')->first());
        $this->actingAs($this->receptionist)->postJson("/api/v1/rooms/{$room->id}/status", ['status' => 'dirty'])->assertOk();
        $this->postJson("/api/v1/rooms/{$room->id}/status", ['status' => 'available'])->assertOk();
        $this->postJson("/api/v1/rooms/{$room->id}/status", ['status' => 'dirty'])->assertOk();

        $tasks = $this->actingAs($this->housekeeper)->getJson('/api/v1/housekeeping/tasks')->assertOk()->json('data');
        $this->assertCount(1, $tasks); // never two open tasks for one room
        $this->assertSame('102', $tasks[0]['room']['number']);
    }

    public function test_a_room_made_dirty_in_bulk_still_shows_in_the_housekeepers_list(): void
    {
        $this->inHotel(fn () => Room::where('number', '102')->update(['status' => 'dirty'])); // no model events (imports, older data)
        $tasks = $this->actingAs($this->housekeeper)->getJson('/api/v1/housekeeping/tasks')->assertOk()->json('data');
        $this->assertSame(['102'], array_column(array_column($tasks, 'room'), 'number'));
        $this->getJson('/api/v1/housekeeping/tasks')->assertJsonCount(1, 'data'); // made once
    }

    public function test_tasks_given_to_someone_else_cannot_be_done(): void
    {
        $room = $this->inHotel(fn () => Room::where('number', '102')->first());
        $other = User::factory()->withRole($this->hotel, 'housekeeping')->create();
        $other->branches()->attach($this->branch->id);

        $this->actingAs($this->manager)->getJson('/api/v1/housekeeping/staff')->assertJsonFragment(['name' => $this->housekeeper->name]);
        $id = $this->postJson('/api/v1/housekeeping/tasks', ['room_id' => $room->id, 'type' => 'inspection', 'priority' => 'high', 'assigned_to' => $other->id])->assertCreated()->json('data.id');
        // Not someone who does housekeeping (e.g. reception): refused.
        $this->postJson('/api/v1/housekeeping/tasks', ['room_id' => $room->id, 'type' => 'inspection', 'priority' => 'high', 'assigned_to' => $this->receptionist->id])
            ->assertJsonPath('errors.assigned_to.0', 'Choose someone from the housekeeping list.');

        $this->actingAs($this->housekeeper)->postJson("/api/v1/housekeeping/tasks/$id/start")->assertJsonValidationErrors('task');
        $this->postJson('/api/v1/housekeeping/tasks', ['room_id' => $room->id, 'type' => 'cleaning', 'priority' => 'low'])->assertForbidden();
        $this->actingAs($other)->postJson("/api/v1/housekeeping/tasks/$id/start")->assertOk();
        $this->assertSame(1, $this->inHotel(fn () => HousekeepingTask::count()));
    }

    /** Owner (QA 8 Nov): never two OPEN tasks of the same kind for one room; the answer names the open task. */
    public function test_a_second_open_task_of_the_same_kind_for_a_room_is_refused(): void
    {
        $room = $this->inHotel(fn () => Room::where('number', '102')->first());
        $this->actingAs($this->manager);
        $first = $this->postJson('/api/v1/housekeeping/tasks', ['room_id' => $room->id, 'type' => 'cleaning', 'priority' => 'normal'])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/housekeeping/tasks', ['room_id' => $room->id, 'type' => 'cleaning', 'priority' => 'high'])
            ->assertStatus(422)
            ->assertJsonPath('errors.room_id.0', 'Room 102 already has an open cleaning task. Change that task instead of adding another one.')
            ->assertJsonPath('existing_task_id', $first);

        // Another kind for the same room is fine; so is a new cleaning task once the first is done.
        $this->postJson('/api/v1/housekeeping/tasks', ['room_id' => $room->id, 'type' => 'maintenance', 'priority' => 'normal'])->assertCreated();
        $this->inHotel(fn () => HousekeepingTask::whereKey($first)->update(['status' => 'done']));
        $this->postJson('/api/v1/housekeeping/tasks', ['room_id' => $room->id, 'type' => 'cleaning', 'priority' => 'normal'])->assertCreated();
        $this->assertSame(2, $this->inHotel(fn () => HousekeepingTask::where('status', '!=', 'done')->count()));
    }

    public function test_changing_a_task_into_a_kind_that_is_already_open_is_refused(): void
    {
        $room = $this->inHotel(fn () => Room::where('number', '102')->first());
        $this->actingAs($this->manager);
        $cleaning = $this->postJson('/api/v1/housekeeping/tasks', ['room_id' => $room->id, 'type' => 'cleaning', 'priority' => 'normal'])->assertCreated()->json('data.id');
        $other = $this->postJson('/api/v1/housekeeping/tasks', ['room_id' => $room->id, 'type' => 'inspection', 'priority' => 'normal'])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/housekeeping/tasks/$other", ['room_id' => $room->id, 'type' => 'cleaning', 'priority' => 'normal'])
            ->assertStatus(422)->assertJsonPath('existing_task_id', $cleaning);
        // Changing the task itself (same kind) still works.
        $this->putJson("/api/v1/housekeeping/tasks/$cleaning", ['room_id' => $room->id, 'type' => 'cleaning', 'priority' => 'high'])->assertOk();
    }

    public function test_roster_and_late_clock_in(): void
    {
        $this->actingAs($this->manager);
        $shift = $this->postJson('/api/v1/shifts', ['branch_id' => $this->branch->id, 'name' => 'Morning', 'start_time' => '08:00', 'end_time' => '16:00'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/shifts/roster', ['user_id' => $this->receptionist->id, 'shift_id' => $shift, 'date' => '2026-11-12'])->assertCreated();
        $this->getJson('/api/v1/shifts/roster?from=2026-11-09')->assertJsonCount(1, 'assignments')->assertJsonPath('assignments.0.date', '2026-11-12');

        $this->actingAs($this->receptionist)->getJson('/api/v1/shifts/mine')->assertJsonPath('data.0.shift.name', 'Morning');
        $this->postJson('/api/v1/attendance/clock')->assertOk()->assertJsonPath('data.status', 'late'); // 09:00 > 08:10
        $this->postJson('/api/v1/attendance/clock')->assertOk()->assertJsonPath('data.clock_out_at', null); // a double tap does not clock out
        $this->getJson('/api/v1/attendance/status')->assertJsonPath('clocked_in_at', '2026-11-12T09:00:00+03:00');
        $this->travel(8)->hours();
        $this->postJson('/api/v1/attendance/clock')->assertOk()->assertJsonPath('data.clock_out_at', '2026-11-12T17:00:00+03:00');

        $this->actingAs($this->manager)->getJson('/api/v1/attendance/report?from=2026-11-12&to=2026-11-12')
            ->assertJsonPath('summary.0.late', 1)->assertJsonPath('summary.0.hours', 8);
    }

    public function test_kiosk_pin_and_manual_entry(): void
    {
        $this->actingAs($this->manager)->postJson("/api/v1/attendance/pin/{$this->housekeeper->id}", ['pin' => '1234'])->assertJsonValidationErrors('pin'); // too easy
        $this->postJson("/api/v1/attendance/pin/{$this->housekeeper->id}", ['pin' => '4825'])->assertOk();
        $this->postJson("/api/v1/attendance/pin/{$this->receptionist->id}", ['pin' => '4825'])->assertJsonValidationErrors('pin');

        // The kiosk is the reception tablet, signed in as the receptionist.
        $this->actingAs($this->receptionist)->postJson('/api/v1/attendance/kiosk', ['pin' => '9999'])->assertJsonValidationErrors('pin');
        $this->postJson('/api/v1/attendance/kiosk', ['pin' => '4825'])->assertOk()->assertJsonPath('name', $this->housekeeper->name)->assertJsonPath('action', 'in');

        $this->actingAs($this->manager)->postJson('/api/v1/attendance/manual', ['user_id' => $this->receptionist->id, 'date' => '2026-11-11', 'clock_in' => '22:00', 'clock_out' => '06:00', 'notes' => 'Paper register'])
            ->assertCreated()->assertJsonPath('data.clock_out_at', '2026-11-12T06:00:00+03:00');
        $this->actingAs($this->receptionist)->postJson('/api/v1/attendance/manual', ['user_id' => $this->receptionist->id, 'date' => '2026-11-11'])->assertForbidden();
    }

    public function test_module_screens_are_closed_when_switched_off(): void
    {
        $this->inHotel(fn () => HotelModule::query()->update(['is_enabled' => false]));
        $this->actingAs($this->admin);
        foreach (['/api/v1/housekeeping/tasks', '/api/v1/shifts', '/api/v1/attendance/status', '/api/v1/cashier/sessions'] as $url) {
            $this->getJson($url)->assertForbidden()->assertJsonPath('code', 'module_off');
        }
    }
}
