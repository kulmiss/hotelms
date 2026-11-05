<?php

namespace Tests\Feature\Reports;

use App\Mail\ReportMail;
use App\Models\Branch;
use App\Models\CashierSession;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\ReportSchedule;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\TelegramLink;
use App\Models\User;
use App\Services\HotelDefaults;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AutomationTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private Branch $branch;

    private User $admin;

    private RoomType $type;

    private Guest $guest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-12 09:00', 'Africa/Addis_Ababa'));
        $this->hotel = Hotel::factory()->create(['plan_id' => Plan::factory()->create(['max_rooms' => null, 'modules' => []])->id, 'status' => 'active']);
        $this->branch = Branch::factory()->for($this->hotel)->create(['check_in_time' => '14:00', 'check_out_time' => '12:00']);
        app(HotelDefaults::class)->apply($this->hotel);
        $this->admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();
        $this->inHotel(function () {
            $this->type = RoomType::create(['branch_id' => $this->branch->id, 'name' => 'Standard', 'base_capacity' => 2, 'max_capacity' => 2, 'nightly_price' => '2000', 'currency' => 'ETB']);
            Room::create(['branch_id' => $this->branch->id, 'room_type_id' => $this->type->id, 'number' => '101', 'status' => 'available']);
            $this->guest = Guest::create(['first_name' => 'Abebe']);
        });
    }

    private function inHotel(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->hotel, $fn);
    }

    public function test_saved_views_are_private_unless_shared_with_a_role(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/saved-views', ['report_key' => 'performance', 'name' => 'Bole this month', 'filters' => ['from' => '2026-11-01', 'to' => '2026-11-30', 'evil' => 'x'], 'is_pinned' => true])
            ->assertCreated()->assertJsonMissingPath('data.filters.evil')->json('data.id');
        $manager = User::factory()->withRole($this->hotel, 'branch_manager')->create();
        $this->actingAs($manager)->getJson('/api/v1/saved-views')->assertJsonCount(0, 'data');
        $this->putJson("/api/v1/saved-views/$id", ['report_key' => 'performance', 'name' => 'x', 'filters' => ['from' => '2026-11-01']])->assertNotFound(); // not mine

        $this->actingAs($this->admin)->putJson("/api/v1/saved-views/$id", ['report_key' => 'performance', 'name' => 'Bole this month', 'filters' => ['from' => '2026-11-01'], 'shared_with_roles' => [$manager->role_id]])->assertOk();
        $this->actingAs($manager)->getJson('/api/v1/saved-views')->assertJsonCount(1, 'data')->assertJsonPath('data.0.mine', false);
    }

    public function test_custom_report_groups_measures(): void
    {
        $this->actingAs($this->admin);
        $res = $this->postJson('/api/v1/custom-reports/run', ['measures' => ['bookings', 'booking_value', 'payments'], 'group_by' => 'source', 'from' => '2026-11-01', 'to' => '2026-11-30']);
        $res->assertOk()->assertJsonCount(0, 'data.rows');

        $this->postJson('/api/v1/reservations', ['branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2026-11-20', 'check_out' => '2026-11-21', 'guest_id' => $this->guest->id, 'source' => 'agent', 'rooms' => [['room_type_id' => $this->type->id]]])->assertCreated();
        $row = $this->postJson('/api/v1/custom-reports/run', ['measures' => ['bookings', 'booking_value', 'payments'], 'group_by' => 'source', 'from' => '2026-11-01', 'to' => '2026-11-30'])->json('data.rows.0');
        $this->assertSame('agent', $row['key']);
        $this->assertSame(1, $row['bookings']);
        $this->assertSame('2300.00', $row['booking_value']);
        $this->assertNull($row['payments']); // payments cannot be grouped by booking source

        $this->postJson('/api/v1/custom-reports', ['name' => 'By source', 'measures' => ['bookings'], 'group_by' => 'source', 'chart_type' => 'bar'])->assertCreated();
        $this->postJson('/api/v1/custom-reports/run', ['measures' => ['nope'], 'group_by' => 'day', 'from' => '2026-11-01', 'to' => '2026-11-02'])->assertJsonValidationErrors('measures.0');
    }

    public function test_daily_report_is_scheduled_by_default_and_sent_by_email(): void
    {
        Mail::fake();
        $this->actingAs($this->admin)->getJson('/api/v1/report-schedules')->assertOk()
            ->assertJsonPath('data.0.report_key', 'daily')->assertJsonPath('data.0.next_run_local', '2026-11-13T00:30:00+03:00');

        $this->travelTo(Carbon::parse('2026-11-13 00:31', 'Africa/Addis_Ababa'));
        $this->artisan('reports:automation')->assertSuccessful();
        Mail::assertSent(ReportMail::class, fn (ReportMail $m) => $m->hasTo($this->admin->email) && str_contains($m->title, 'Daily manager report') && $m->fileName !== null);
        $s = $this->inHotel(fn () => ReportSchedule::first());
        $this->assertSame('2026-11-13 21:30:00', $s->next_run_at->toDateTimeString()); // tomorrow 00:30 Addis = 21:30 UTC
    }

    public function test_weekly_schedule_and_send_now(): void
    {
        Mail::fake();
        $this->actingAs($this->admin);
        $id = $this->postJson('/api/v1/report-schedules', ['name' => 'Weekly payments', 'report_key' => 'payments', 'frequency' => 'weekly', 'day' => 1, 'time' => '08:00', 'channels' => ['email'], 'recipients' => [$this->admin->id], 'extra_emails' => ['owner@example.com']])
            ->assertCreated()->json('data.id');
        $s = $this->inHotel(fn () => ReportSchedule::find($id));
        $this->assertSame('2026-11-16 05:00:00', $s->next_run_at->toDateTimeString()); // Monday 08:00 Addis

        $this->postJson("/api/v1/report-schedules/$id/test")->assertOk()->assertJsonPath('sent', true);
        Mail::assertSent(ReportMail::class, 2);
    }

    public function test_smart_alerts_fire_once_and_can_be_acknowledged(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/reservations', ['branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2026-11-14', 'check_out' => '2026-11-15', 'guest_id' => $this->guest->id, 'rooms' => [['room_type_id' => $this->type->id]]])->assertCreated();
        $this->inHotel(function () {
            $s = CashierSession::create(['branch_id' => $this->branch->id, 'user_id' => $this->admin->id, 'opened_at' => now()->subHours(8), 'closed_at' => now()->subHour(), 'status' => 'closed']);
            $s->balances()->create(['currency' => 'ETB', 'opening_amount' => '0', 'expected_amount' => '1000', 'counted_amount' => '700', 'difference' => '-300']);
        });

        $this->travel(22)->hours(); // pending booking expires in 2 hours
        $this->artisan('reports:automation --alerts')->assertSuccessful();
        $this->artisan('reports:automation --alerts')->assertSuccessful(); // same problems: no duplicates

        $events = $this->getJson('/api/v1/alert-events')->assertOk()->json('data');
        $this->assertCount(2, $events);
        $this->assertTrue(collect($events)->contains(fn ($e) => str_contains($e['title'], 'Cash short ETB 300')));
        $this->postJson("/api/v1/alert-events/{$events[0]['id']}/ack")->assertOk();
        $this->getJson('/api/v1/alert-events')->assertJsonPath('unread', 1);

        $receptionist = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $this->actingAs($receptionist)->getJson('/api/v1/alert-events')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/alert-rules')->assertForbidden();
        $this->postJson("/api/v1/alert-events/{$events[1]['id']}/ack")->assertNotFound(); // not sent to them
    }

    public function test_alert_rules_can_be_switched_off(): void
    {
        $this->actingAs($this->admin);
        $rules = $this->getJson('/api/v1/alert-rules')->assertOk()->assertJsonCount(6, 'data')->json('data');
        $cash = collect($rules)->firstWhere('type', 'cash_short');
        $this->putJson("/api/v1/alert-rules/{$cash['id']}", ['is_active' => false, 'threshold' => ['amount' => 500, 'junk' => 1], 'channels' => ['app', 'telegram'], 'recipients' => []])
            ->assertOk()->assertJsonPath('data.threshold.amount', 500)->assertJsonMissingPath('data.threshold.junk');
    }

    public function test_telegram_link_by_code(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/telegram/link')->assertJsonValidationErrors('telegram'); // not set up
        PlatformSetting::write('telegram_bot_token', '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ');
        PlatformSetting::write('telegram_bot_username', 'HotelMSBot');
        $code = $this->postJson('/api/v1/telegram/link')->assertOk()->assertJsonPath('data.bot', 'HotelMSBot')->json('data.code');

        Http::fake([
            'api.telegram.org/*/getUpdates' => Http::response(['ok' => true, 'result' => [['update_id' => 7, 'message' => ['text' => "/start {$code}", 'chat' => ['id' => 555, 'first_name' => 'Meron']]]]]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
        $this->artisan('reports:automation')->assertSuccessful();
        $this->assertSame('555', TelegramLink::where('user_id', $this->admin->id)->value('chat_id'));
        $this->getJson('/api/v1/telegram')->assertJsonPath('linked', true);
        $this->assertSame(8, PlatformSetting::read('telegram_offset'));
    }

    public function test_demo_hotels_never_send(): void
    {
        Mail::fake();
        $this->hotel->update(['is_demo' => true]);
        $this->actingAs($this->admin)->getJson('/api/v1/report-schedules')->assertOk();
        $id = $this->inHotel(fn () => ReportSchedule::first()->id);
        $this->postJson("/api/v1/report-schedules/$id/test")->assertOk();
        Mail::assertNothingSent();
    }

    public function test_super_admin_platform_reports_and_hotel_health(): void
    {
        $sa = User::factory()->superAdmin()->create();
        $this->actingAs($sa)->getJson('/api/v1/admin/reports/platform')->assertOk()->assertJsonCount(12, 'data.income')->assertJsonPath('data.rooms', 1);
        $row = collect($this->getJson('/api/v1/admin/reports/hotel-health')->assertOk()->json('data'))->firstWhere('id', $this->hotel->id);
        $this->assertContains($row['health'], ['healthy', 'watch', 'at_risk', 'stuck']);
        $this->actingAs($this->admin)->getJson('/api/v1/admin/reports/platform')->assertForbidden();
    }
}
