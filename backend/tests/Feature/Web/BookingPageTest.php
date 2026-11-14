<?php

namespace Tests\Feature\Web;

use App\Models\AppNotification;
use App\Models\BookingRequest;
use App\Models\Branch;
use App\Models\FeatureSwitch;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Plan;
use App\Models\RoomType;
use App\Models\User;
use App\Services\HotelDefaults;
use App\Services\Switches;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** A hotel's public booking page (owner, 14 Nov 2026, "2a"): off by default, requests only, no cookies, "Powered by" on Free. */
class BookingPageTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private User $admin;

    private Branch $branch;

    private RoomType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hotel = Hotel::factory()->create(['name' => 'Lake Tana Lodge', 'status' => 'active', 'plan_id' => Plan::where('code', 'free')->value('id')]);
        app(HotelDefaults::class)->apply($this->hotel);
        $this->branch = Branch::factory()->for($this->hotel)->create();
        $this->type = app(TenantContext::class)->runAs($this->hotel, fn () => RoomType::create(['branch_id' => $this->branch->id, 'name' => 'Lake view', 'base_capacity' => 2, 'max_capacity' => 3, 'nightly_price' => '2500', 'currency' => 'ETB']));
        $this->admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();
    }

    private function turnOn(array $extra = []): string
    {
        return $this->actingAs($this->admin)->putJson('/api/v1/settings/booking-page', $extra + ['enabled' => true, 'note' => 'Breakfast included.'])
            ->assertOk()->assertJsonPath('data.enabled', true)->json('data.slug');
    }

    private function body(array $extra = []): array
    {
        return $extra + [
            'branch_id' => $this->branch->id, 'room_type_id' => $this->type->id, 'check_in' => now()->addDays(3)->toDateString(), 'check_out' => now()->addDays(5)->toDateString(),
            'adults' => 2, 'children' => 0, 'name' => 'Abebe Kebede', 'phone' => '+251 911 223 344', 'email' => '', 'note' => 'Late arrival',
            'form_token' => Crypt::encryptString((string) (now()->timestamp - 20)), 'website' => '',
        ];
    }

    public function test_the_page_is_off_until_the_hotel_turns_it_on(): void
    {
        $this->get('/h/lake-tana-lodge')->assertNotFound();
        $slug = $this->turnOn();
        $this->assertSame('lake-tana-lodge', $slug);
        $r = $this->get("/h/{$slug}")->assertOk();
        $this->assertEmpty($r->headers->getCookies());
        $this->assertStringContainsString("form-action 'self'", $r->headers->get('Content-Security-Policy'));
        $r->assertSee('Lake view')->assertSee('ETB 2,500')->assertSee('Breakfast included.')->assertSee('Powered by');
        $this->assertStringNotContainsString('<script', $r->getContent());
    }

    public function test_a_guest_sends_a_request_and_reception_is_told(): void
    {
        $slug = $this->turnOn();
        $receptionist = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $receptionist->branches()->attach($this->branch);
        $this->post("/h/{$slug}", $this->body())->assertRedirect("/h/{$slug}?sent=1");
        $this->get("/h/{$slug}?sent=1")->assertSee('Your request was sent.');

        $req = BookingRequest::withoutGlobalScope('hotel')->firstOrFail();
        $this->assertSame([$this->hotel->id, 'new', 'Abebe Kebede', $this->type->id], [$req->hotel_id, $req->status, $req->name, $req->room_type_id]);
        // No network address is kept for guests.
        $this->assertFalse(Schema::hasColumn('booking_requests', 'ip'));
        $this->assertStringNotContainsString('127.0.0.1', json_encode(DB::table('booking_requests')->first()));
        $this->assertTrue(AppNotification::where('user_id', $receptionist->id)->where('source', 'booking_request')->exists());

        $this->actingAs($receptionist)->getJson('/api/v1/booking-requests')->assertOk()->assertJsonPath('data.0.name', 'Abebe Kebede')->assertJsonPath('new', 1);
        $this->postJson("/api/v1/booking-requests/{$req->id}/decline")->assertOk()->assertJsonPath('data.status', 'declined');
        $this->postJson("/api/v1/booking-requests/{$req->id}/decline")->assertJsonValidationErrors('status'); // once
    }

    public function test_bad_input_and_bots_are_refused_without_saving(): void
    {
        $slug = $this->turnOn();
        $this->post("/h/{$slug}", $this->body(['website' => 'spam']))->assertRedirect("/h/{$slug}?sent=1");
        $this->post("/h/{$slug}", $this->body(['form_token' => Crypt::encryptString((string) now()->timestamp)]))->assertStatus(422)->assertSee('check the form');
        $this->post("/h/{$slug}", $this->body(['check_in' => now()->subDay()->toDateString()]))->assertStatus(422)->assertSee('Choose today or a later day.');
        $this->post("/h/{$slug}", $this->body(['phone' => 'call me']))->assertStatus(422);
        $this->post("/h/{$slug}", $this->body(['check_out' => now()->addDays(90)->toDateString()]))->assertStatus(422)->assertSee('please call the hotel');
        $this->assertSame(0, BookingRequest::withoutGlobalScope('hotel')->count());
    }

    public function test_another_hotels_room_or_branch_cannot_be_used(): void
    {
        $slug = $this->turnOn();
        $other = Hotel::factory()->create();
        app(HotelDefaults::class)->apply($other);
        $otherBranch = Branch::factory()->for($other)->create();
        $otherType = app(TenantContext::class)->runAs($other, fn () => RoomType::create(['branch_id' => $otherBranch->id, 'name' => 'Theirs', 'base_capacity' => 2, 'max_capacity' => 2, 'nightly_price' => '100', 'currency' => 'ETB']));
        $this->post("/h/{$slug}", $this->body(['branch_id' => $otherBranch->id]))->assertStatus(422);
        $this->post("/h/{$slug}", $this->body(['room_type_id' => $otherType->id]))->assertStatus(422);
        $this->assertSame(0, BookingRequest::withoutGlobalScope('hotel')->count());
        // And other hotels' staff never see the requests.
        $this->post("/h/{$slug}", $this->body())->assertRedirect();
        $this->actingAs(User::factory()->withRole($other, 'hotel_admin')->create())->getJson('/api/v1/booking-requests')->assertJsonCount(0, 'data');
    }

    public function test_accepting_links_the_booking_made_with_the_normal_form(): void
    {
        $slug = $this->turnOn();
        $this->post("/h/{$slug}", $this->body())->assertRedirect();
        $req = BookingRequest::withoutGlobalScope('hotel')->firstOrFail();
        $reservationId = DB::table('reservations')->insertGetId([
            'hotel_id' => $this->hotel->id, 'branch_id' => $this->branch->id, 'code' => 'RES-T-1', 'guest_id' => app(TenantContext::class)->runAs($this->hotel, fn () => Guest::create(['first_name' => 'Abebe'])->id),
            'source' => 'other', 'stay_type' => 'nightly', 'check_in_at' => now()->addDays(3), 'check_out_at' => now()->addDays(5), 'status' => 'confirmed', 'currency' => 'ETB',
            'subtotal' => '5000', 'total' => '5000', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->admin)->postJson("/api/v1/booking-requests/{$req->id}/accept", ['reservation_id' => $reservationId])->assertOk()
            ->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.reservation_id', $reservationId);
    }

    public function test_powered_by_goes_away_with_branding_and_locked_or_suspended_hotels_have_no_page(): void
    {
        $slug = $this->turnOn();
        FeatureSwitch::create(['key' => 'feature.branding', 'level' => 'hotel', 'target_id' => $this->hotel->id, 'value' => true]);
        app(Switches::class)->flush();
        $this->get("/h/{$slug}")->assertOk()->assertDontSee('Powered by');

        $this->hotel->forceFill(['signup_status' => 'pending'])->save();
        $this->get("/h/{$slug}")->assertNotFound();
        $this->hotel->forceFill(['signup_status' => null, 'status' => 'suspended', 'suspended_at' => now()])->save();
        $this->get("/h/{$slug}")->assertNotFound();
    }

    public function test_only_settings_staff_change_the_page_and_the_link_is_unique(): void
    {
        $receptionist = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $this->actingAs($receptionist)->putJson('/api/v1/settings/booking-page', ['enabled' => true])->assertForbidden();
        $other = Hotel::factory()->create(['booking_slug' => 'taken']);
        $this->actingAs($this->admin)->putJson('/api/v1/settings/booking-page', ['enabled' => true, 'slug' => 'taken'])->assertJsonValidationErrors('slug');
        $this->putJson('/api/v1/settings/booking-page', ['enabled' => true, 'slug' => 'My Lodge'])->assertOk()->assertJsonPath('data.slug', 'my-lodge')
            ->assertJsonPath('data.url', rtrim((string) config('app.url'), '/').'/h/my-lodge');
        $this->assertNotNull($other);
    }
}
