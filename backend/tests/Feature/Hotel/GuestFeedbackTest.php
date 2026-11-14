<?php

namespace Tests\Feature\Hotel;

use App\Mail\ReportMail;
use App\Models\AppNotification;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\GuestFeedback;
use App\Models\Hotel;
use App\Models\Plan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BookingPage;
use App\Services\HotelDefaults;
use App\Services\HotelSettings;
use App\Services\Switches;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Guest feedback (owner, 14 Nov 2026): secure link per stay, no cookies, one answer, alert at 3 stars or less, dashboard. */
class GuestFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private Branch $branch;

    private User $admin;

    private User $manager;

    private RoomType $type;

    private Room $room;

    private Guest $guest;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-14 09:00', 'Africa/Addis_Ababa'));
        $this->hotel = Hotel::factory()->create(['name' => 'Sheger Hotel', 'plan_id' => Plan::where('code', 'starter')->value('id'), 'status' => 'active', 'default_currency' => 'ETB', 'booking_slug' => 'sheger']);
        app(HotelDefaults::class)->apply($this->hotel);
        $this->branch = Branch::factory()->for($this->hotel)->create();
        $this->admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();
        $this->manager = User::factory()->withRole($this->hotel, 'branch_manager')->create();
        $this->manager->branches()->attach($this->branch->id);
        $this->inHotel(function () {
            $this->type = RoomType::create(['branch_id' => $this->branch->id, 'name' => 'Standard', 'base_capacity' => 2, 'max_capacity' => 2, 'nightly_price' => '2000', 'currency' => 'ETB']);
            $this->room = Room::create(['branch_id' => $this->branch->id, 'room_type_id' => $this->type->id, 'number' => '101', 'status' => 'available']);
            $this->guest = Guest::create(['first_name' => 'Abebe', 'last_name' => 'Kebede', 'phone' => '+251 911 223344', 'email' => 'abebe@guest.test']);
        });
    }

    private function inHotel(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->hotel, $fn);
    }

    /** A stay that ended; returns the feedback link from the thank-you email (or null). */
    private function stay(bool $consent, string $in = '2026-11-10', string $out = '2026-11-12'): ?string
    {
        $this->inHotel(function () use ($consent, $in, $out) {
            $r = Reservation::create(['branch_id' => $this->branch->id, 'code' => 'R-'.$in, 'guest_id' => $this->guest->id, 'source' => 'phone', 'stay_type' => 'nightly',
                'check_in_at' => Carbon::parse("{$in} 14:00", 'Africa/Addis_Ababa'), 'check_out_at' => Carbon::parse("{$out} 12:00", 'Africa/Addis_Ababa'), 'adults' => 1, 'children' => 0,
                'status' => 'checked_in', 'currency' => 'ETB', 'subtotal' => '4000', 'discount_percent' => '0', 'discount_amount' => '0', 'tax_total' => '0', 'total' => '4000',
                'deposit_required' => '0', 'paid_total' => '4000', 'feedback_consent' => $consent, 'checked_in_by' => $this->manager->id]);
            $r->rooms()->create(['room_type_id' => $this->type->id, 'room_id' => $this->room->id, 'check_in_at' => $r->check_in_at, 'check_out_at' => $r->check_out_at, 'blocked_until' => $r->check_out_at, 'adults' => 1, 'children' => 0, 'total_amount' => '4000', 'status' => 'checked_in']);
            $r->update(['status' => 'checked_out', 'checked_out_by' => $this->admin->id]);
        });
        $mail = Mail::sent(ReportMail::class, fn (ReportMail $m) => str_contains($m->title, 'Thank you for staying') || str_contains($m->title, 'ስለቆዩ እናመሰግናለን'))->last();
        if (! $mail) {
            return null;
        }
        preg_match('#/f/[A-Za-z0-9]{40}#', implode("\n", $mail->lines), $m);

        return $m[0] ?? null;
    }

    public function test_the_guest_answers_once_with_no_cookies_and_a_low_rating_alerts_managers(): void
    {
        $this->assertNull($this->stay(false)); // no yes → no thank-you, no link
        $this->assertSame(0, $this->inHotel(fn () => GuestFeedback::count()));

        $path = $this->stay(true, '2026-11-12', '2026-11-13');
        $this->assertNotNull($path);
        $page = $this->get($path)->assertOk();
        $this->assertEmpty($page->headers->getCookies());
        $page->assertSee('How was your stay?')->assertSee('Sheger Hotel');
        $this->assertStringNotContainsString('<script', $page->getContent());

        $this->post($path, ['cleanliness' => 2])->assertStatus(422)->assertSee('Please choose the stars');
        $this->post($path, ['overall' => 2, 'cleanliness' => 1, 'staff' => 4, 'comment' => 'The shower was cold.', 'public_ok' => '1'])->assertRedirect($path.'?sent=1&lang=en');
        $this->get($path.'?sent=1')->assertSee('Thank you for your feedback!');
        $f = $this->inHotel(fn () => GuestFeedback::firstOrFail());
        $this->assertSame([2, 1, 4, null, 'The shower was cold.', true, $this->manager->id, $this->room->id], [$f->overall, $f->cleanliness, $f->staff, $f->comfort, $f->comment, $f->public_ok, $f->checked_in_by, $f->room_id]);
        $this->assertTrue(AppNotification::where('user_id', $this->manager->id)->where('source', 'low_rating')->where('title', 'like', 'Low rating: 2 of 5%')->exists());

        // Never a second answer, never changed.
        $this->post($path, ['overall' => 5, 'comment' => 'Great'])->assertRedirect($path.'?sent=1&lang=en');
        $this->assertSame([2, 'The shower was cold.'], $this->inHotel(fn () => [GuestFeedback::first()->overall, GuestFeedback::first()->comment]));
        $this->get('/f/'.str_repeat('Z', 40))->assertNotFound();
    }

    public function test_staff_read_reply_and_see_the_dashboard_but_never_change_the_guests_words(): void
    {
        $path = $this->stay(true);
        $this->post($path, ['overall' => 4, 'cleanliness' => 5, 'staff' => 3, 'comment' => 'Nice room'])->assertRedirect();
        $id = $this->inHotel(fn () => GuestFeedback::first()->id);

        $list = $this->actingAs($this->manager)->getJson('/api/v1/feedback')->assertOk();
        $this->assertSame(['Abebe Kebede', '101', 4, 'Nice room', $this->manager->name, $this->admin->name], [$list->json('data.0.guest'), $list->json('data.0.room'), $list->json('data.0.overall'), $list->json('data.0.comment'), $list->json('data.0.checked_in_by'), $list->json('data.0.checked_out_by')]);
        $this->putJson("/api/v1/feedback/{$id}/reply", ['reply' => 'Called the guest.', 'overall' => 5, 'comment' => 'Perfect'])->assertOk();
        $f = $this->inHotel(fn () => GuestFeedback::find($id));
        $this->assertSame([4, 'Nice room', 'Called the guest.'], [$f->overall, $f->comment, $f->reply]);

        $sum = $this->getJson('/api/v1/feedback/summary')->assertOk()->json('data');
        $this->assertSame([1, true], [$sum['count'], $sum['dashboard']]);
        $this->assertEquals(4, $sum['averages']['overall']);
        $this->assertSame('101', $sum['rooms'][0]['room']);
        $this->assertContains($this->manager->name, array_column($sum['staff'], 'name'));

        $receptionist = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $this->actingAs($receptionist)->getJson('/api/v1/feedback')->assertForbidden();
        $other = User::factory()->withRole(Hotel::factory()->create(), 'hotel_admin')->create();
        $this->actingAs($other)->putJson("/api/v1/feedback/{$id}/reply", ['reply' => 'x'])->assertNotFound();
    }

    public function test_free_plan_has_the_list_and_averages_only(): void
    {
        $this->hotel->update(['plan_id' => Plan::where('code', 'free')->value('id')]);
        app(Switches::class)->flush();
        $this->manager->unsetRelation('hotel');
        $sum = $this->actingAs($this->manager)->getJson('/api/v1/feedback/summary')->assertOk()->json('data');
        $this->assertFalse($sum['dashboard']);
        $this->assertArrayNotHasKey('trend', $sum);
    }

    public function test_the_booking_page_shows_reviews_only_when_the_hotel_and_the_guest_allow_it(): void
    {
        $this->inHotel(fn () => app(HotelSettings::class)->put([BookingPage::ENABLED => true]));
        $p1 = $this->stay(true, '2026-11-01', '2026-11-02');
        $this->post($p1, ['overall' => 5, 'comment' => 'Lovely breakfast', 'public_ok' => '1'])->assertRedirect();
        $p2 = $this->stay(true, '2026-11-03', '2026-11-04');
        $this->post($p2, ['overall' => 3, 'comment' => 'Noisy street'])->assertRedirect(); // not allowed to show

        $this->get('/h/sheger')->assertOk()->assertDontSee('What guests say');
        $this->inHotel(fn () => app(HotelSettings::class)->put([BookingPage::REVIEWS => true]));
        $html = $this->get('/h/sheger')->assertOk();
        $html->assertSee('What guests say')->assertSee('4.0 of 5 from 2 guests')->assertSee('Lovely breakfast')->assertSee('Abebe')->assertDontSee('Noisy street')->assertDontSee('Kebede');
        $this->get('/h/sheger?lang=am')->assertSee('እንግዶች የሚሉት');
    }

    public function test_the_link_expires_after_60_days_and_speaks_amharic(): void
    {
        $this->inHotel(fn () => app(HotelSettings::class)->put(['language' => 'am']));
        $path = $this->stay(true);
        $this->get($path)->assertOk()->assertSee('ቆይታዎ እንዴት ነበር?');
        $this->travel(61)->days();
        $this->get($path.'?lang=en')->assertOk()->assertSee('This link has expired');
    }
}
