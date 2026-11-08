<?php

namespace Tests\Feature\Automation;

use App\Mail\ReportMail;
use App\Models\AppNotification;
use App\Models\Approval;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\HousekeepingTask;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\RateRule;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\HotelDefaults;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AutomationsTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private Branch $branch;

    private User $admin;

    private User $clerk;

    private RoomType $standard;

    private Guest $guest;

    private int $cash;

    private int $mobile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 09:00', 'Africa/Addis_Ababa'));
        $this->hotel = Hotel::factory()->create(['plan_id' => Plan::factory()->create(['max_rooms' => null, 'modules' => []])->id, 'default_currency' => 'ETB', 'status' => 'active']);
        $this->branch = Branch::factory()->for($this->hotel)->create(['name' => 'Bole', 'code' => 'BL', 'check_in_time' => '14:00', 'check_out_time' => '12:00']);
        app(HotelDefaults::class)->apply($this->hotel);
        $this->admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();
        $this->clerk = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $this->clerk->branches()->attach($this->branch->id);
        $this->inHotel(function () {
            $this->standard = RoomType::create(['branch_id' => $this->branch->id, 'name' => 'Standard', 'base_capacity' => 2, 'max_capacity' => 2, 'nightly_price' => '2000', 'currency' => 'ETB']);
            foreach (['101', '102'] as $n) {
                Room::create(['branch_id' => $this->branch->id, 'room_type_id' => $this->standard->id, 'number' => $n, 'status' => 'available']);
            }
            $this->guest = Guest::create(['first_name' => 'Abebe', 'last_name' => 'Kebede', 'phone' => '+251 911 223344', 'email' => 'abebe@guest.test']);
            $this->cash = PaymentMethod::where('name', 'Cash')->value('id');
            $this->mobile = PaymentMethod::where('type', 'mobile_money')->value('id') ?? PaymentMethod::create(['name' => 'Telebirr', 'type' => 'mobile_money', 'is_active' => true])->id;
        });
    }

    private function inHotel(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->hotel, $fn);
    }

    private function book(array $extra = []): int
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/reservations', array_merge([
            'branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2026-11-20', 'check_out' => '2026-11-22',
            'guest_id' => $this->guest->id, 'rooms' => [['room_type_id' => $this->standard->id, 'adults' => 2]],
        ], $extra))->assertCreated()->json('data.id');
    }

    private function notes(User $user): array
    {
        return AppNotification::where('user_id', $user->id)->pluck('title')->all();
    }

    public function test_a_confirmed_booking_emails_the_guest_once(): void
    {
        Mail::fake();
        $id = $this->book();
        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'deposit', 'payment_method_id' => $this->cash, 'amount' => '1380'])->assertJsonPath('data.status', 'confirmed');
        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'payment', 'payment_method_id' => $this->cash, 'amount' => '100'])->assertCreated();

        Mail::assertSent(ReportMail::class, fn (ReportMail $m) => $m->hasTo('abebe@guest.test') && str_contains($m->title, 'Booking confirmed'));
        Mail::assertSentCount(1);
    }

    public function test_the_hotel_and_hotelms_can_turn_an_automation_off(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->putJson('/api/v1/settings/automations/booking_confirmation', ['enabled' => false])->assertOk();
        $id = $this->book();
        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'deposit', 'payment_method_id' => $this->cash, 'amount' => '1380']);
        Mail::assertNothingSent();

        // Turned on again by the hotel, but HotelMS switched it off for this hotel.
        $this->putJson('/api/v1/settings/automations/booking_confirmation', ['enabled' => true])->assertOk();
        $owner = User::factory()->superAdmin()->create();
        $this->actingAs($owner)->postJson('/api/v1/admin/switches', ['key' => 'automation.booking_confirmation', 'level' => 'hotel', 'target_id' => $this->hotel->id, 'value' => false])->assertCreated();
        $this->actingAs($this->admin)->getJson('/api/v1/settings/automations')->assertOk()
            ->assertJsonFragment(['key' => 'booking_confirmation', 'blocked' => 'hotelms']);
        $id = $this->book(['check_in' => '2026-11-25', 'check_out' => '2026-11-26']);
        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'deposit', 'payment_method_id' => $this->cash, 'amount' => '1000']);
        Mail::assertNothingSent();
    }

    public function test_a_transfer_without_reference_reminds_the_person_who_took_it(): void
    {
        $id = $this->book();
        $this->actingAs($this->clerk)->postJson("/api/v1/reservations/$id/payments", ['type' => 'deposit', 'payment_method_id' => $this->mobile, 'amount' => '500'])->assertCreated();

        $this->assertStringContainsString('Add the reference number', $this->notes($this->clerk)[0] ?? '');
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('unread', 1);
        $this->postJson('/api/v1/notifications/read')->assertOk();
        $this->getJson('/api/v1/notifications')->assertJsonPath('unread', 0);
        // Nobody else sees it.
        $this->actingAs($this->admin)->getJson('/api/v1/notifications')->assertJsonCount(0, 'data');
    }

    public function test_a_hotel_rule_fires_and_can_be_tested_on_past_bookings(): void
    {
        $this->book();
        $rule = [
            'name' => 'Long stays', 'trigger' => 'booking_created',
            'conditions' => [['field' => 'nights', 'op' => 'above', 'value' => 1]],
            'actions' => [['type' => 'notify', 'permission' => 'frontdesk.check_in', 'message' => 'Long stay booked']],
        ];
        $this->actingAs($this->admin)->postJson('/api/v1/settings/automation-rules/test', $rule)->assertOk()->assertJsonPath('count', 1);
        $this->postJson('/api/v1/settings/automation-rules', $rule)->assertCreated();

        $this->book(['check_in' => '2026-11-25', 'check_out' => '2026-11-28']);
        $this->assertContains('Long stay booked', $this->notes($this->clerk));
        $this->book(['check_in' => '2026-11-29', 'check_out' => '2026-11-30']); // one night: no
        $this->assertCount(1, array_filter($this->notes($this->clerk), fn ($t) => $t === 'Long stay booked'));

        // Room actions only with room events.
        $this->postJson('/api/v1/settings/automation-rules', ['name' => 'x', 'trigger' => 'booking_created', 'actions' => [['type' => 'room_status', 'status' => 'dirty']]])
            ->assertJsonValidationErrors('actions.0.type');
    }

    /** Owner (QA 8 Nov): a rule never adds a second open task of the same kind for one room. */
    public function test_a_rule_adds_no_second_open_task_of_the_same_kind(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/settings/automation-rules', [
            'name' => 'Check the room', 'trigger' => 'room_status_changed',
            'actions' => [['type' => 'housekeeping_task', 'task' => 'inspection', 'message' => 'Check the room']],
        ])->assertCreated();
        $room = $this->inHotel(fn () => Room::first());

        $this->postJson("/api/v1/rooms/{$room->id}/status", ['status' => 'maintenance'])->assertOk();
        $this->postJson("/api/v1/rooms/{$room->id}/status", ['status' => 'available'])->assertOk();

        $this->assertSame(1, $this->inHotel(fn () => HousekeepingTask::where('room_id', $room->id)->where('type', 'inspection')->where('status', '!=', 'done')->count()));
    }

    public function test_a_cancelled_paid_booking_prepares_a_refund_for_approval(): void
    {
        $id = $this->book();
        $this->postJson("/api/v1/reservations/$id/payments", ['type' => 'deposit', 'payment_method_id' => $this->cash, 'amount' => '1380']);
        $this->postJson("/api/v1/reservations/$id/cancel", ['reason' => 'Plans changed'])->assertOk(); // free: 10 days before

        $approval = $this->getJson('/api/v1/approvals')->assertOk()->assertJsonPath('data.0.kind', 'refund')->json('data.0');
        $this->assertSame('1380.00', $approval['payload']['amount']);
        $this->postJson("/api/v1/approvals/{$approval['id']}/approve", ['payment_method_id' => $this->cash])->assertOk();
        $this->getJson("/api/v1/reservations/$id")->assertJsonPath('data.paid_total', '0.00');
        $this->postJson("/api/v1/approvals/{$approval['id']}/approve", ['payment_method_id' => $this->cash])->assertNotFound(); // only once

        // A receptionist cannot open the inbox.
        $this->actingAs($this->clerk)->getJson('/api/v1/approvals')->assertForbidden();
    }

    public function test_an_approved_price_suggestion_becomes_a_one_day_price_rule(): void
    {
        $approval = $this->inHotel(fn () => Approval::create([
            'branch_id' => $this->branch->id, 'kind' => 'price_up', 'title' => '+10% on Fri', 'status' => 'pending', 'fingerprint' => 'price:x',
            'payload' => ['date' => '2026-11-20', 'percent' => 10, 'occupancy' => 90, 'usual' => 60],
        ]));
        $this->actingAs($this->admin)->postJson("/api/v1/approvals/{$approval->id}/approve")->assertOk();

        $rule = $this->inHotel(fn () => RateRule::first());
        $this->assertSame('season', $rule->rule_type);
        $this->assertSame('10.00', (string) $rule->value);
        $this->assertSame('2026-11-20', $rule->start_date->toDateString());
    }

    public function test_a_price_suggestion_for_yesterday_cannot_be_approved_just_after_midnight(): void
    {
        $approval = $this->inHotel(fn () => Approval::create([
            'branch_id' => $this->branch->id, 'kind' => 'price_up', 'title' => '+10% on Tue', 'status' => 'pending', 'fingerprint' => 'price:y',
            'payload' => ['date' => '2026-11-10', 'percent' => 10, 'occupancy' => 90, 'usual' => 60],
        ]));
        // 00:30 on 11 Nov in Addis Ababa = still 10 Nov on the server's clock (UTC): the hotel's day counts.
        $this->travelTo(Carbon::parse('2026-11-11 00:30', 'Africa/Addis_Ababa'));
        $this->actingAs($this->admin)->postJson("/api/v1/approvals/{$approval->id}/approve")->assertJsonValidationErrors('approval');
        $this->assertSame(0, $this->inHotel(fn () => RateRule::count()));
    }

    public function test_reminders_from_the_clock_are_sent_once(): void
    {
        $id = $this->book(['check_in' => '2026-11-11', 'check_out' => '2026-11-12']); // pending, expires within the deposit time
        $this->travelTo(now()->addHours(22)); // close to the expiry
        $this->artisan('automations:run')->assertSuccessful();
        $this->artisan('automations:run')->assertSuccessful();

        $reminders = array_filter($this->notes($this->clerk), fn ($t) => str_starts_with($t, 'Collect the deposit'));
        $this->assertCount(1, $reminders, 'Expected exactly one reminder for booking '.$id);
    }

    public function test_quiet_hours_and_other_hotels(): void
    {
        $this->actingAs($this->admin)->putJson('/api/v1/settings/automations/quiet-hours', ['quiet_hours' => true, 'quiet_from' => '22:00', 'quiet_to' => '07:00'])
            ->assertOk()->assertJsonPath('quiet.quiet_from', '22:00');
        $this->putJson('/api/v1/settings/automations/large_discount', ['enabled' => true, 'options' => ['amount' => '500']])->assertOk()
            ->assertJsonFragment(['key' => 'large_discount', 'options' => ['amount' => '500']]);

        $other = Hotel::factory()->create(['plan_id' => $this->hotel->plan_id]);
        Branch::factory()->for($other)->create();
        app(HotelDefaults::class)->apply($other);
        $stranger = User::factory()->withRole($other, 'hotel_admin')->create();
        $this->actingAs($stranger)->getJson('/api/v1/settings/automations')->assertOk()->assertJsonCount(0, 'rules')
            ->assertJsonFragment(['key' => 'large_discount', 'options' => ['amount' => '1000']]);
        $this->actingAs($this->clerk)->getJson('/api/v1/settings/automations')->assertForbidden();
    }
}
