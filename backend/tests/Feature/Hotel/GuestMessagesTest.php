<?php

namespace Tests\Feature\Hotel;

use App\Mail\ReportMail;
use App\Models\BookingRequest;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\GuestMessage;
use App\Models\Hotel;
use App\Models\Plan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Automation\AutomationEvents;
use App\Services\BookingPage;
use App\Services\HotelDefaults;
use App\Services\HotelSettings;
use App\Services\Switches;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Guest messages (owner, 14 Nov 2026): templates EN/AM, consent and opt-out, email, SMS on paid plans, WhatsApp by a tap, log. */
class GuestMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private Branch $branch;

    private User $admin;

    private User $clerk;

    private RoomType $type;

    private Guest $guest;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake(['api.afromessage.com/*' => Http::response(['acknowledge' => 'success', 'response' => ['message_id' => '1']])]);
        $this->travelTo(Carbon::parse('2026-11-14 09:00', 'Africa/Addis_Ababa'));
        $this->hotel = Hotel::factory()->create(['name' => 'Sheger Hotel', 'phone' => '+251 11 555 0000', 'plan_id' => Plan::where('code', 'starter')->value('id'), 'status' => 'active', 'default_currency' => 'ETB']);
        app(HotelDefaults::class)->apply($this->hotel);
        $this->branch = Branch::factory()->for($this->hotel)->create(['check_in_time' => '14:00', 'check_out_time' => '12:00']);
        $this->admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();
        $this->clerk = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $this->clerk->branches()->attach($this->branch->id);
        $this->inHotel(function () {
            $this->type = RoomType::create(['branch_id' => $this->branch->id, 'name' => 'Standard', 'base_capacity' => 2, 'max_capacity' => 2, 'nightly_price' => '2000', 'currency' => 'ETB']);
            Room::create(['branch_id' => $this->branch->id, 'room_type_id' => $this->type->id, 'number' => '101', 'status' => 'available']);
            $this->guest = Guest::create(['first_name' => 'Abebe', 'last_name' => 'Kebede', 'phone' => '+251 911 223344', 'email' => 'abebe@guest.test']);
        });
    }

    private function inHotel(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->hotel, $fn);
    }

    private function book(array $extra = []): Reservation
    {
        $id = $this->actingAs($this->admin)->postJson('/api/v1/reservations', $extra + [
            'branch_id' => $this->branch->id, 'stay_type' => 'nightly', 'check_in' => '2026-11-20', 'check_out' => '2026-11-22',
            'guest_id' => $this->guest->id, 'rooms' => [['room_type_id' => $this->type->id]], 'pay_later' => false,
        ])->assertCreated()->json('data.id');

        return $this->inHotel(fn () => Reservation::findOrFail($id));
    }

    private function setStatus(Reservation $r, string $status): void
    {
        $this->inHotel(fn () => $r->refresh()->update(['status' => $status]));
    }

    private function sent(string $title): array
    {
        return Mail::sent(ReportMail::class, fn (ReportMail $m) => str_contains($m->title, $title))->values()->all();
    }

    public function test_confirmation_welcome_and_thank_you_follow_consent_and_settings(): void
    {
        $this->actingAs($this->admin)->putJson('/api/v1/settings/guest-messages/info', ['wifi_name' => 'Sheger-Guest', 'wifi_password' => 'tea2026', 'breakfast' => '07:00–10:00', 'whatsapp' => '+251 911 000 111', 'menu_url' => 'https://example.com/menu'])->assertOk();
        $r = $this->book();
        $this->setStatus($r, 'confirmed');
        $mail = $this->sent('Booking confirmed: '.$r->code);
        $this->assertCount(1, $mail);
        $this->assertContains('Arrive: Fri 20 Nov 2026', $mail[0]->lines);
        $this->assertTrue($mail[0]->hasTo('abebe@guest.test'));

        $this->setStatus($r, 'checked_in');
        $welcome = $this->sent('Welcome to Sheger Hotel')[0];
        $this->assertContains('Wi-Fi: Sheger-Guest · password: tea2026', $welcome->lines);
        $this->assertContains('Our menu: https://example.com/menu', $welcome->lines);

        $this->setStatus($r, 'checked_out'); // no consent → no thank-you
        $this->assertCount(0, $this->sent('Thank you for staying'));
        $this->assertSame(['confirmation', 'welcome'], $this->inHotel(fn () => GuestMessage::orderBy('id')->pluck('key')->all()));

        // With a yes, the thank-you goes; a line with no value (no feedback link yet) is left out.
        $r2 = $this->book(['check_in' => '2026-11-25', 'check_out' => '2026-11-26', 'feedback_consent' => true]);
        $this->setStatus($r2, 'checked_in');
        $this->setStatus($r2, 'checked_out');
        $thanks = $this->sent('Thank you for staying at Sheger Hotel');
        $this->assertCount(1, $thanks);
        $this->assertEmpty(array_filter($thanks[0]->lines, fn ($l) => str_contains($l, 'feedback') || str_contains($l, '{')));
    }

    public function test_no_messages_for_a_guest_who_said_no_and_none_from_the_demo_hotel(): void
    {
        $this->actingAs($this->admin)->putJson("/api/v1/guests/{$this->guest->id}", ['first_name' => 'Abebe', 'last_name' => 'Kebede', 'phone' => '+251 911 223344', 'email' => 'abebe@guest.test', 'messages_opt_out' => true])->assertOk();
        $r = $this->book();
        $this->setStatus($r, 'confirmed');
        $this->setStatus($r, 'checked_in');
        Mail::assertNothingSent();
        $this->postJson("/api/v1/reservations/{$r->id}/messages", ['key' => 'welcome', 'channel' => 'whatsapp'])->assertJsonValidationErrors(['channel' => 'asked for no messages']);

        $this->hotel->update(['is_demo' => true]);
        app()->forgetInstance(AutomationEvents::class);
        $this->actingAs($this->admin)->putJson("/api/v1/guests/{$this->guest->id}", ['first_name' => 'Abebe', 'messages_opt_out' => false])->assertOk();
        $r2 = $this->book(['check_in' => '2026-12-01', 'check_out' => '2026-12-02']);
        $this->setStatus($r2, 'confirmed');
        Mail::assertNothingSent();
    }

    public function test_amharic_template_edit_reset_and_preview(): void
    {
        $this->actingAs($this->admin)->putJson("/api/v1/guests/{$this->guest->id}", ['first_name' => 'Abebe', 'last_name' => 'Kebede', 'email' => 'abebe@guest.test', 'phone' => '+251 911 223344', 'language' => 'am'])->assertOk();
        $r = $this->book();
        $p = $this->getJson("/api/v1/reservations/{$r->id}/messages/preview?key=confirmation")->assertOk();
        $this->assertSame('am', $p->json('data.language'));
        $this->assertStringContainsString('ውድ Abebe Kebede', $p->json('data.body'));
        $this->assertStringContainsString('11 ኅዳር 2019 ዓ.ም. (20/11/2026)', $p->json('data.body')); // Ethiopian date first

        $this->putJson('/api/v1/settings/guest-messages/templates/confirmation/en', ['subject' => 'Yes! {booking_code}', 'body' => "Hi {guest_name}\n{unknown} stays"])->assertOk();
        $en = $this->getJson("/api/v1/reservations/{$r->id}/messages/preview?key=confirmation&language=en")->json('data');
        $this->assertSame(['Yes! '.$r->code, "Hi Abebe Kebede\n{unknown} stays"], [$en['subject'], $en['body']]);
        $this->deleteJson('/api/v1/settings/guest-messages/templates/confirmation/en')->assertOk()->assertJsonPath('data.custom', false);
        $this->actingAs($this->clerk)->putJson('/api/v1/settings/guest-messages/templates/confirmation/en', ['subject' => 'x', 'body' => 'y'])->assertForbidden();
    }

    public function test_whatsapp_and_sms_app_by_a_tap_are_logged(): void
    {
        $r = $this->book();
        $wa = $this->actingAs($this->clerk)->postJson("/api/v1/reservations/{$r->id}/messages", ['key' => 'pre_arrival', 'channel' => 'whatsapp'])->assertOk();
        $this->assertStringStartsWith('https://wa.me/251911223344?text=Dear%20Abebe%20Kebede', $wa->json('data.link'));
        $sms = $this->postJson("/api/v1/reservations/{$r->id}/messages", ['key' => 'pre_arrival', 'channel' => 'sms_app'])->json('data.link');
        $this->assertStringStartsWith('sms:+251911223344?body=', $sms);
        $log = $this->getJson("/api/v1/reservations/{$r->id}/messages")->assertOk();
        $this->assertSame(['sms_app', 'whatsapp'], array_column($log->json('data'), 'channel'));
        $this->assertSame('opened', $log->json('data.0.status'));
        $this->assertSame($this->clerk->name, $log->json('data.0.by'));
        Http::assertNothingSent();
    }

    public function test_automatic_sms_on_paid_plans_with_the_hotels_own_account(): void
    {
        $this->actingAs($this->admin)->putJson('/api/v1/settings/guest-messages/sms', ['provider' => 'afromessage', 'token' => 'tok-'.str_repeat('x', 20), 'sender' => 'SHEGER'])->assertOk()->assertJsonPath('data.has_token', true);
        $this->assertStringNotContainsString('tok-', $this->getJson('/api/v1/settings/guest-messages')->getContent());
        $this->assertStringNotContainsString('tok-', (string) $this->inHotel(fn () => \DB::table('hotel_settings')->where('key', 'sms_gateway')->value('value')));
        $auto = $this->getJson('/api/v1/settings/guest-messages')->json('auto');
        $auto['confirmation']['sms'] = true;
        $this->putJson('/api/v1/settings/guest-messages/auto', $auto)->assertOk();

        $r = $this->book();
        $this->setStatus($r, 'confirmed');
        Http::assertSent(fn ($req) => $req->url() === 'https://api.afromessage.com/api/send' && $req['to'] === '+251911223344' && $req['sender'] === 'SHEGER' && $req->hasHeader('Authorization', 'Bearer tok-'.str_repeat('x', 20)));
        $this->assertSame(['email', 'sms'], $this->inHotel(fn () => GuestMessage::orderBy('channel')->pluck('channel')->all()));

    }

    public function test_free_plan_sends_no_automatic_sms(): void
    {
        $this->hotel->update(['plan_id' => Plan::where('code', 'free')->value('id')]);
        app(Switches::class)->flush();
        $this->admin->unsetRelation('hotel');
        $this->actingAs($this->admin)->putJson('/api/v1/settings/guest-messages/sms', ['provider' => 'afromessage', 'token' => 'tok-'.str_repeat('y', 20)])->assertOk();
        $auto = $this->getJson('/api/v1/settings/guest-messages')->assertJsonPath('auto_sms', false)->json('auto');
        $auto['confirmation']['sms'] = true;
        $this->putJson('/api/v1/settings/guest-messages/auto', $auto)->assertOk();
        $r = $this->book();
        $this->setStatus($r, 'confirmed');
        Http::assertNothingSent();
        $this->assertSame(['email'], $this->inHotel(fn () => GuestMessage::pluck('channel')->all()));
    }

    public function test_a_generic_sms_address_must_be_on_the_internet(): void
    {
        $this->actingAs($this->admin)->putJson('/api/v1/settings/guest-messages/sms', ['provider' => 'http', 'url' => 'http://127.0.0.1:8080/send?to={to}&text={message}', 'method' => 'GET'])->assertOk();
        $this->postJson('/api/v1/settings/guest-messages/sms/test', ['phone' => '+251911223344'])->assertJsonValidationErrors(['phone' => 'on the internet']);
        Http::assertNothingSent();
    }

    public function test_booking_page_request_received_and_declined(): void
    {
        $this->inHotel(fn () => app(HotelSettings::class)->put([BookingPage::ENABLED => true]));
        $this->hotel->update(['booking_slug' => 'sheger']);
        $body = ['branch_id' => $this->branch->id, 'room_type_id' => $this->type->id, 'check_in' => '2026-11-20', 'check_out' => '2026-11-22', 'adults' => 2, 'children' => 0,
            'name' => 'Sara Alemu', 'phone' => '+251 922 111 222', 'email' => 'sara@guest.test', 'feedback_consent' => '1',
            'form_token' => Crypt::encryptString((string) (now()->timestamp - 20)), 'website' => ''];
        $this->post('/h/sheger?lang=am', $body)->assertRedirect('/h/sheger?sent=1&lang=am');
        $mail = $this->sent('ጥያቄዎ ደርሶናል');
        $this->assertCount(1, $mail);
        $req = $this->inHotel(fn () => BookingRequest::firstOrFail());
        $this->assertSame([true, 'am'], [$req->feedback_consent, $req->language]);

        $this->actingAs($this->clerk)->postJson("/api/v1/booking-requests/{$req->id}/decline")->assertOk();
        $this->assertCount(1, $this->sent('ጥያቄዎ – Sheger Hotel'));
    }

    public function test_other_hotels_cannot_see_or_send(): void
    {
        $r = $this->book();
        $other = Hotel::factory()->create();
        $otherAdmin = User::factory()->withRole($other, 'hotel_admin')->create();
        $this->actingAs($otherAdmin)->getJson("/api/v1/reservations/{$r->id}/messages")->assertNotFound();
        $this->actingAs($otherAdmin)->postJson("/api/v1/reservations/{$r->id}/messages", ['key' => 'welcome', 'channel' => 'email'])->assertNotFound();
    }
}
