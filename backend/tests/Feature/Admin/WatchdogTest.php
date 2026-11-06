<?php

namespace Tests\Feature\Admin;

use App\Mail\ReportMail;
use App\Models\Branch;
use App\Models\Hotel;
use App\Models\Plan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Models\WatchdogFinding;
use App\Services\HotelDefaults;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WatchdogTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Hotel $a;

    private Hotel $b;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->owner = User::factory()->superAdmin()->create(['email' => 'owner@hotelms.test']);
        $plan = Plan::factory()->create(['max_rooms' => null, 'modules' => []]);
        foreach (['a' => 'Sheba Hotel', 'b' => 'Lalibela Inn'] as $key => $name) {
            $this->{$key} = Hotel::factory()->create(['name' => $name, 'status' => 'active', 'plan_id' => $plan->id]);
            Branch::factory()->for($this->{$key})->create();
            app(HotelDefaults::class)->apply($this->{$key});
        }
    }

    private function check(): void
    {
        $this->artisan('watchdog:run --data')->assertSuccessful();
    }

    private function open(string $check)
    {
        return WatchdogFinding::where('check', $check)->whereNull('resolved_at')->get();
    }

    public function test_password_guessing_is_found_and_the_owner_is_warned_by_bell_and_email(): void
    {
        for ($i = 0; $i < 12; $i++) {
            DB::table('watch_events')->insert(['kind' => 'login_failed', 'ip' => '196.188.1.9', 'message' => "guess{$i}@sheba.test", 'created_at' => now()]);
        }
        $this->check();

        $f = $this->open('brute_force')->first();
        $this->assertSame('critical', $f->severity);
        $this->assertStringContainsString('196.188.1.9', $f->title);
        $told = fn () => DB::table('app_notifications')->where('user_id', $this->owner->id)->where('source', 'watchdog')->where('title', 'like', '%196.188.1.9%')->count();
        $this->assertSame(1, $told());
        Mail::assertSent(ReportMail::class, fn (ReportMail $m) => $m->hasTo('owner@hotelms.test') && str_contains($m->title, 'Watchdog'));

        // Told once, not every 10 minutes.
        $this->check();
        $this->assertSame(1, $told());

        // Gone after the window: closes by itself.
        $this->travel(20)->minutes();
        $this->check();
        $this->assertCount(0, $this->open('brute_force'));
    }

    public function test_someone_trying_other_hotels_ids_is_named(): void
    {
        $staff = User::factory()->withRole($this->a, 'hotel_admin')->create(['name' => 'Curious Clerk']);
        $theirs = app(TenantContext::class)->runAs($this->b, fn () => Branch::first()->id);
        $this->actingAs($staff);
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/v1/reservations/'.(1000 + $i))->assertNotFound(); // ids that are not theirs
            app(TenantContext::class)->set(null);
        }
        $this->check();

        $f = $this->open('snooping')->first();
        $this->assertNotNull($f, 'Expected a snooping finding (branch '.$theirs.')');
        $this->assertSame($this->a->id, $f->hotel_id);
        $this->assertStringContainsString('Curious Clerk', json_encode($f->details, JSON_UNESCAPED_SLASHES));
    }

    public function test_data_of_two_hotels_mixed_is_found_and_closes_when_repaired(): void
    {
        [$room, $branchA] = app(TenantContext::class)->runAs($this->a, function () {
            $type = RoomType::create(['branch_id' => Branch::first()->id, 'name' => 'Standard', 'base_capacity' => 2, 'max_capacity' => 2, 'nightly_price' => '1000', 'currency' => 'ETB']);

            return [Room::create(['branch_id' => Branch::first()->id, 'room_type_id' => $type->id, 'number' => '101', 'status' => 'available']), Branch::first()->id];
        });
        $branchB = app(TenantContext::class)->runAs($this->b, fn () => Branch::first()->id);
        DB::table('rooms')->where('id', $room->id)->update(['branch_id' => $branchB]); // a bug or tampering

        $this->check();
        $f = $this->open('mixed_hotels')->first();
        $this->assertSame('critical', $f->severity);
        $this->assertSame($this->a->id, $f->hotel_id);
        $this->assertStringContainsString('Lalibela Inn', json_encode($f->details, JSON_UNESCAPED_SLASHES));

        DB::table('rooms')->where('id', $room->id)->update(['branch_id' => $branchA]);
        $this->check();
        $this->assertCount(0, $this->open('mixed_hotels'));
    }

    public function test_server_errors_say_where_the_problem_is(): void
    {
        for ($i = 0; $i < 3; $i++) {
            DB::table('watch_events')->insert(['kind' => 'exception', 'user_id' => null, 'hotel_id' => $this->a->id, 'path' => '/api/v1/reports/daily', 'status' => 500,
                'message' => 'DivisionByZeroError: Division by zero @ app/Services/Reports/Insights.php:120', 'created_at' => now()]);
        }
        $this->check();

        $f = $this->open('server_error')->first();
        $this->assertStringContainsString('Insights.php:120', json_encode($f->details, JSON_UNESCAPED_SLASHES));
        $this->assertStringContainsString('/api/v1/reports/daily', json_encode($f->details, JSON_UNESCAPED_SLASHES));
    }

    public function test_only_the_owner_and_tech_see_it_and_can_mark_fixed_or_mute(): void
    {
        DB::table('watch_events')->insert(array_fill(0, 12, ['kind' => 'login_failed', 'ip' => '10.0.0.1', 'message' => 'x@y.test', 'created_at' => now()]));
        $this->check();
        $id = $this->open('brute_force')->first()->id;

        $support = User::factory()->superAdmin()->create(['platform_role' => 'support']);
        $this->actingAs($support)->getJson('/api/v1/admin/watchdog')->assertForbidden();

        $tech = User::factory()->superAdmin()->create(['platform_role' => 'tech']);
        $this->actingAs($tech)->getJson('/api/v1/admin/watchdog')->assertOk()->assertJsonPath('counts.critical', $this->open('brute_force')->count() + $this->open('system')->where('severity', 'critical')->count());
        $this->postJson("/api/v1/admin/watchdog/{$id}/mute", ['days' => 7])->assertOk();
        $this->postJson("/api/v1/admin/watchdog/{$id}/resolve")->assertOk();
        $this->assertNotNull(WatchdogFinding::find($id)->resolved_at);

        // Hotel staff never.
        $this->actingAs(User::factory()->withRole($this->a, 'hotel_admin')->create())->getJson('/api/v1/admin/watchdog')->assertForbidden();
    }
}
