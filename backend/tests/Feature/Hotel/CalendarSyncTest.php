<?php

namespace Tests\Feature\Hotel;

use App\Models\AppNotification;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\IcalBlock;
use App\Models\IcalFeed;
use App\Models\Plan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\HotelDefaults;
use App\Services\Switches;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Calendar sync with Airbnb / Booking.com by iCal links (owner, 14 Nov 2026): import, block, warn, export. */
class CalendarSyncTest extends TestCase
{
    use RefreshDatabase;

    private const AIRBNB = 'https://www.airbnb.com/calendar/ical/123.ics?s=secretpart';

    private Hotel $hotel;

    private Branch $branch;

    private User $admin;

    private RoomType $type;

    private Room $r101;

    private Room $r102;

    private Guest $guest;

    private string $nextBody = '';

    private int $nextStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['www.airbnb.com/*' => fn () => Http::response($this->nextBody, $this->nextStatus, ['Content-Type' => 'text/calendar'])]);
        $this->travelTo(Carbon::parse('2026-11-14 09:00', 'Africa/Addis_Ababa'));
        $this->hotel = Hotel::factory()->create(['plan_id' => Plan::where('code', 'professional')->value('id'), 'status' => 'active', 'default_currency' => 'ETB']);
        app(HotelDefaults::class)->apply($this->hotel);
        $this->branch = Branch::factory()->for($this->hotel)->create(['check_in_time' => '14:00', 'check_out_time' => '12:00']);
        $this->admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();
        $this->inHotel(function () {
            $this->type = RoomType::create(['branch_id' => $this->branch->id, 'name' => 'Standard', 'base_capacity' => 2, 'max_capacity' => 2, 'nightly_price' => '2000', 'currency' => 'ETB']);
            $this->r101 = Room::create(['branch_id' => $this->branch->id, 'room_type_id' => $this->type->id, 'number' => '101', 'status' => 'available']);
            $this->r102 = Room::create(['branch_id' => $this->branch->id, 'room_type_id' => $this->type->id, 'number' => '102', 'status' => 'available']);
            $this->guest = Guest::create(['first_name' => 'Abebe', 'last_name' => 'Kebede', 'phone' => '+251 911 223344']);
        });
    }

    private function inHotel(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->hotel, $fn);
    }

    private function ics(array $events): string
    {
        $out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Airbnb Inc//Hosting Calendar//EN\r\n";
        foreach ($events as [$uid, $start, $end, $summary, $status]) {
            $out .= "BEGIN:VEVENT\r\nDTSTAMP:20261114T060000Z\r\nDTSTART;VALUE=DATE:{$start}\r\nDTEND;VALUE=DATE:{$end}\r\nSUMMARY:{$summary}\r\nUID:{$uid}\r\n".($status ? "STATUS:{$status}\r\n" : '')."END:VEVENT\r\n";
        }

        return $out."END:VCALENDAR\r\n";
    }

    /** What the "Airbnb" link answers next. */
    private function fake(array $events, int $status = 200): void
    {
        [$this->nextBody, $this->nextStatus] = [$status === 200 ? $this->ics($events) : 'error', $status];
    }

    private function book(Room $room, string $in, string $out, int $status = 201): ?int
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/reservations', [
            'branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => $in, 'check_out' => $out, 'guest_id' => $this->guest->id,
            'rooms' => [['room_type_id' => $this->type->id, 'room_id' => $room->id]],
        ])->assertStatus($status)->json('data.id');
    }

    public function test_an_imported_link_blocks_its_room_and_shows_on_the_calendar(): void
    {
        $this->fake([['a1@airbnb.com', '20261120', '20261123', 'Reserved', null], ['a2@airbnb.com', '20261201', '20261202', "Airbnb (Not\r\n  available)", null], ['a3@airbnb.com', '20261205', '20261207', 'Reserved', 'CANCELLED']]);
        $r = $this->actingAs($this->admin)->postJson('/api/v1/settings/calendar-sync', ['name' => 'Airbnb', 'url' => self::AIRBNB, 'room_id' => $this->r101->id])->assertCreated();
        $this->assertSame([true, 2, 0], [$r->json('sync.ok'), $r->json('sync.events'), $r->json('sync.conflicts')]);

        $list = $this->getJson('/api/v1/settings/calendar-sync')->assertOk();
        $this->assertSame(['www.airbnb.com', 'ok', 2], [$list->json('data.0.host'), $list->json('data.0.last_status'), $list->json('data.0.events_count')]);
        $this->assertStringNotContainsString('secretpart', $list->getContent()); // the secret link never comes back
        $this->assertStringNotContainsString('secretpart', (string) $this->inHotel(fn () => \DB::table('ical_feeds')->value('url'))); // stored encrypted

        // Room 101 is not free on those nights; check-out day (23rd) is free again; room 102 is free.
        $this->book($this->r101, '2026-11-21', '2026-11-22', 422);
        $this->book($this->r101, '2026-11-23', '2026-11-24');
        $this->book($this->r102, '2026-11-21', '2026-11-22');

        $cal = $this->getJson("/api/v1/reservations/calendar?branch_id={$this->branch->id}&from=2026-11-18&days=14")->assertOk();
        $this->assertSame(['Airbnb', $this->r101->id, '2026-11-20T14:00:00+03:00'], [$cal->json('blocks.0.source'), $cal->json('blocks.0.room_id'), $cal->json('blocks.0.check_in_at')]);
        $this->assertSame('Airbnb (Not available)', $cal->json('blocks.1.summary')); // folded line joined

        // Gone from Airbnb → free again at the next sync; a failing link keeps the old dates.
        $this->fake([['a2@airbnb.com', '20261201', '20261202', 'Reserved', null]]);
        $this->travel(2)->minutes();
        $this->postJson('/api/v1/settings/calendar-sync/'.$list->json('data.0.id').'/sync')->assertOk()->assertJsonPath('sync.events', 1);
        $this->book($this->r101, '2026-11-20', '2026-11-21');
        $this->fake([], 500);
        $this->travel(2)->minutes();
        $this->postJson('/api/v1/settings/calendar-sync/'.$list->json('data.0.id').'/sync')->assertOk()->assertJsonPath('sync.ok', false);
        $this->assertSame(1, $this->inHotel(fn () => IcalBlock::count()));
        $this->assertSame('error', $this->getJson('/api/v1/settings/calendar-sync')->json('data.0.last_status'));
        $this->postJson('/api/v1/settings/calendar-sync/'.$list->json('data.0.id').'/sync')->assertJsonValidationErrors('sync'); // once a minute
    }

    public function test_a_room_type_link_holds_one_room_of_the_type(): void
    {
        $this->fake([['t1@booking.com', '20261120', '20261122', 'CLOSED - Not available', null]]);
        $this->actingAs($this->admin)->postJson('/api/v1/settings/calendar-sync', ['name' => 'Booking.com', 'url' => self::AIRBNB, 'room_type_id' => $this->type->id])->assertCreated();
        $this->book($this->r101, '2026-11-20', '2026-11-21');
        $this->actingAs($this->admin)->postJson('/api/v1/reservations', ['branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2026-11-20', 'check_out' => '2026-11-21', 'guest_id' => $this->guest->id, 'rooms' => [['room_type_id' => $this->type->id]]])
            ->assertJsonValidationErrors('rooms'); // 2 rooms: one booked, one held by Booking.com
    }

    public function test_a_clash_with_a_booking_is_a_warning_and_nothing_is_cancelled(): void
    {
        $id = $this->book($this->r101, '2026-11-21', '2026-11-22');
        $receptionist = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $receptionist->branches()->attach($this->branch->id);
        $this->fake([['a1@airbnb.com', '20261120', '20261123', 'Reserved', null]]);
        $this->actingAs($this->admin)->postJson('/api/v1/settings/calendar-sync', ['name' => 'Airbnb', 'url' => self::AIRBNB, 'room_id' => $this->r101->id])->assertCreated()->assertJsonPath('sync.conflicts', 1);

        $feed = $this->getJson('/api/v1/settings/calendar-sync')->json('data.0');
        $this->assertStringContainsString('in the same room', $feed['conflicts'][0]['text']);
        $this->assertNotSame('cancelled', $this->inHotel(fn () => Reservation::find($id)->status));
        $this->assertSame(1, AppNotification::where('user_id', $receptionist->id)->where('source', 'ical_conflict')->count());

        // The same clash again is not told twice.
        $this->travel(2)->minutes();
        $this->postJson("/api/v1/settings/calendar-sync/{$feed['id']}/sync")->assertOk();
        $this->assertSame(1, AppNotification::where('user_id', $receptionist->id)->where('source', 'ical_conflict')->count());
    }

    public function test_links_inside_the_servers_network_are_refused(): void
    {
        foreach (['http://127.0.0.1/cal.ics', 'http://localhost/cal.ics', 'http://192.168.1.10/cal.ics', 'http://[::1]/cal.ics'] as $url) {
            $this->actingAs($this->admin)->postJson('/api/v1/settings/calendar-sync', ['name' => 'X', 'url' => $url, 'room_id' => $this->r101->id])->assertCreated()->assertJsonPath('sync.ok', false);
        }
        Http::assertNothingSent();
        $this->postJson('/api/v1/settings/calendar-sync', ['name' => 'X', 'url' => 'file:///etc/passwd', 'room_id' => $this->r101->id])->assertJsonValidationErrors('url');
    }

    public function test_each_room_has_a_secret_export_link_without_guest_names(): void
    {
        $this->book($this->r101, '2026-11-20', '2026-11-22');
        $url = $this->actingAs($this->admin)->postJson("/api/v1/settings/calendar-sync/rooms/{$this->r101->id}/export")->assertOk()->json('export_url');
        $this->assertMatchesRegularExpression('#/ical/[A-Za-z0-9]{40}\.ics$#', $url);
        $path = parse_url($url, PHP_URL_PATH);

        $ics = $this->get($path)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=UTF-8');
        $this->assertEmpty($ics->headers->getCookies());
        $body = $ics->getContent();
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20261120\r\nDTEND;VALUE=DATE:20261122\r\nSUMMARY:Booked", $body);
        $this->assertStringNotContainsString('Abebe', $body);
        $this->assertStringNotContainsString('ical_secret', $this->getJson('/api/v1/settings/room-types')->getContent());

        // Reset: the old link stops working; off: no link.
        $new = $this->postJson("/api/v1/settings/calendar-sync/rooms/{$this->r101->id}/export")->json('export_url');
        $this->assertNotSame($url, $new);
        $this->get($path)->assertNotFound();
        $this->deleteJson("/api/v1/settings/calendar-sync/rooms/{$this->r101->id}/export")->assertOk();
        $this->get(parse_url($new, PHP_URL_PATH))->assertNotFound();
        $this->get('/ical/'.str_repeat('a', 40).'.ics')->assertNotFound();
    }

    public function test_plan_limits(): void
    {
        $this->fake([]);
        $this->hotel->update(['plan_id' => Plan::where('code', 'free')->value('id')]);
        app(Switches::class)->flush();
        $this->actingAs($this->admin)->postJson('/api/v1/settings/calendar-sync', ['name' => 'Airbnb', 'url' => self::AIRBNB, 'room_id' => $this->r101->id])
            ->assertJsonValidationErrors(['room_id' => 'not in your plan']);
        $this->postJson("/api/v1/settings/calendar-sync/rooms/{$this->r101->id}/export")->assertJsonValidationErrors('room_id');

        $this->hotel->update(['plan_id' => Plan::where('code', 'starter')->value('id')]);
        app(Switches::class)->flush();
        $this->admin->unsetRelation('hotel');
        $this->inHotel(function () {
            foreach (range(1, 9) as $n) {
                Room::create(['branch_id' => $this->branch->id, 'room_type_id' => $this->type->id, 'number' => (string) (200 + $n), 'status' => 'available']);
            }
        });
        $this->postJson('/api/v1/settings/calendar-sync', ['name' => 'Booking.com', 'url' => self::AIRBNB, 'room_type_id' => $this->type->id])
            ->assertJsonValidationErrors(['room_id' => 'up to 10 rooms']); // 11 rooms of the type
        $this->postJson('/api/v1/settings/calendar-sync', ['name' => 'Airbnb', 'url' => self::AIRBNB, 'room_id' => $this->r101->id])->assertCreated();
        $this->assertSame(['used' => 1, 'max' => 10], $this->getJson('/api/v1/settings/calendar-sync')->json('limit'));
    }

    public function test_the_scheduler_reads_links_every_15_minutes_and_hotels_stay_apart(): void
    {
        $this->fake([['a1@airbnb.com', '20261120', '20261123', 'Reserved', null]]);
        $this->actingAs($this->admin)->postJson('/api/v1/settings/calendar-sync', ['name' => 'Airbnb', 'url' => self::AIRBNB, 'room_id' => $this->r101->id])->assertCreated();
        $this->fake([]);
        $this->artisan('ical:sync')->expectsOutputToContain('Synced 0')->assertSuccessful(); // read 0 minutes ago
        $this->travel(16)->minutes();
        $this->artisan('ical:sync')->expectsOutputToContain('Synced 1')->assertSuccessful();
        $this->assertSame(0, $this->inHotel(fn () => IcalBlock::count()));

        $other = Hotel::factory()->create();
        $otherAdmin = User::factory()->withRole($other, 'hotel_admin')->create();
        $id = $this->inHotel(fn () => IcalFeed::first()->id);
        $this->actingAs($otherAdmin)->putJson("/api/v1/settings/calendar-sync/{$id}", ['name' => 'Mine', 'room_id' => $this->r101->id])->assertNotFound();
        $this->actingAs($otherAdmin)->getJson('/api/v1/settings/calendar-sync')->assertOk()->assertJsonCount(0, 'data');
    }
}
