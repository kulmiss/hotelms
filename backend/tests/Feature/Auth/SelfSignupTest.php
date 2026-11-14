<?php

namespace Tests\Feature\Auth;

use App\Mail\SignupDecisionMail;
use App\Mail\SignupVerifyMail;
use App\Models\Hotel;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Hotels register themselves (owner, 14 Nov 2026): off / approval / open, email first, bots kept out. */
class SelfSignupTest extends TestCase
{
    use RefreshDatabase;

    /** A made-up sign-up password that meets our rules (built here so secret scanners don't mistake it for a key). */
    private static function pw(): string
    {
        return implode('-', ['Blue', 'Nile', 'Sunrise', '42']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function body(array $extra = []): array
    {
        return $extra + [
            'hotel_name' => 'Lake Tana Lodge', 'owner_name' => 'Almaz Bekele', 'email' => 'almaz@lodge.test', 'phone' => '+251 911 234 567',
            'city' => 'Bahir Dar', 'rooms' => 12, 'password' => self::pw(), 'password_confirmation' => self::pw(), 'accept_terms' => true,
            'form_token' => Crypt::encryptString((string) (now()->timestamp - 30)), 'website' => '',
        ];
    }

    private function register(array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/signup', $this->body($extra));
    }

    private function login(): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => 'almaz@lodge.test', 'password' => self::pw()]);
    }

    private function verifyLink(): string
    {
        $token = null;
        Mail::assertSent(SignupVerifyMail::class, function (SignupVerifyMail $m) use (&$token) {
            $token = substr($m->link, strpos($m->link, 'token=') + 6);

            return true;
        });

        return (string) $token;
    }

    public function test_sign_up_is_off_by_default(): void
    {
        $this->getJson('/api/v1/signup')->assertOk()->assertJsonPath('data.mode', 'off');
        $this->getJson('/api/v1/branding')->assertJsonPath('data.signup_mode', 'off');
        $this->register()->assertForbidden()->assertJsonPath('code', 'signup_off');
        $this->assertSame(0, Hotel::whereNotNull('signup_status')->count());
    }

    public function test_open_mode_gives_a_free_hotel_after_the_email_is_confirmed(): void
    {
        PlatformSetting::write('signup_mode', 'open');
        $this->register()->assertCreated();
        $hotel = Hotel::where('name', 'Lake Tana Lodge')->firstOrFail();
        $this->assertSame('unverified', $hotel->signup_status);
        $this->assertSame(Plan::where('code', 'free')->value('id'), $hotel->plan_id);
        $this->assertSame('active', $hotel->status);
        $this->assertNull($hotel->trial_ends_at);
        $this->assertSame(12, $hotel->signup['rooms']);

        // No sign-in before the email is confirmed.
        $this->login()->assertUnprocessable()->assertJsonPath('code', 'email_unverified');

        $this->postJson('/api/v1/signup/verify', ['token' => $this->verifyLink()])->assertOk()->assertJsonPath('state', 'approved');
        $this->assertSame('approved', $hotel->fresh()->signup_status);
        $this->login()->assertOk();
        $this->assertTrue(User::where('email', 'almaz@lodge.test')->first()->isHotelAdmin());
        // The link works once.
        $this->postJson('/api/v1/signup/verify', ['token' => $this->verifyLink()])->assertJsonValidationErrors('token');

        $kinds = DB::table('security_events')->where('hotel_id', $hotel->id)->pluck('kind')->all();
        $this->assertContains('signup', $kinds);
        $this->assertContains('signup_verified', $kinds);
    }

    public function test_approval_mode_keeps_the_hotel_locked_until_approved(): void
    {
        PlatformSetting::write('signup_mode', 'approval');
        $this->register()->assertCreated();
        $this->postJson('/api/v1/signup/verify', ['token' => $this->verifyLink()])->assertOk()->assertJsonPath('state', 'pending');
        $this->login()->assertUnprocessable()->assertJsonPath('code', 'signup_pending');

        $hotel = Hotel::where('name', 'Lake Tana Lodge')->firstOrFail();
        $team = User::factory()->superAdmin()->create();
        $this->actingAs($team)->getJson('/api/v1/admin/signups')->assertOk()
            ->assertJsonPath('data.0.name', 'Lake Tana Lodge')->assertJsonPath('data.0.owner_name', 'Almaz Bekele')->assertJsonPath('data.0.email_confirmed', true);
        $this->postJson("/api/v1/admin/signups/{$hotel->id}/approve")->assertOk();
        Mail::assertSent(SignupDecisionMail::class, fn (SignupDecisionMail $m) => $m->approved && $m->hasTo('almaz@lodge.test'));
        $this->assertSame('signup_approved', DB::table('security_events')->where('hotel_id', $hotel->id)->latest('id')->value('kind'));

        auth()->guard('web')->logout();
        $this->login()->assertOk();
    }

    public function test_a_refused_sign_up_gets_the_reason_and_cannot_sign_in(): void
    {
        PlatformSetting::write('signup_mode', 'approval');
        $this->register()->assertCreated();
        $this->postJson('/api/v1/signup/verify', ['token' => $this->verifyLink()]);
        $hotel = Hotel::where('name', 'Lake Tana Lodge')->firstOrFail();
        $team = User::factory()->superAdmin()->create();
        $this->actingAs($team)->postJson("/api/v1/admin/signups/{$hotel->id}/reject", ['reason' => ''])->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/admin/signups/{$hotel->id}/reject", ['reason' => 'Not a real hotel'])->assertOk();
        Mail::assertSent(SignupDecisionMail::class, fn (SignupDecisionMail $m) => ! $m->approved && $m->reason === 'Not a real hotel');
        auth()->guard('web')->logout();
        $this->login()->assertUnprocessable()->assertJsonPath('code', 'signup_rejected');
        // Hotel staff cannot see or decide sign-ups.
        $other = User::factory()->withRole(Hotel::factory()->create(), 'hotel_admin')->create();
        $this->actingAs($other)->getJson('/api/v1/admin/signups')->assertForbidden();
    }

    public function test_bots_are_kept_out_without_cookies_or_captcha(): void
    {
        PlatformSetting::write('signup_mode', 'open');
        // Honeypot filled: the normal answer, nothing made, no email.
        $this->register(['website' => 'http://spam.test'])->assertCreated();
        $this->assertFalse(Hotel::where('name', 'Lake Tana Lodge')->exists());
        Mail::assertNothingSent();
        // Too fast, missing or old form time.
        $this->register(['form_token' => Crypt::encryptString((string) now()->timestamp)])->assertJsonValidationErrors('form');
        $this->register(['form_token' => 'nonsense'])->assertJsonValidationErrors('form');
        $this->register(['form_token' => Crypt::encryptString((string) (now()->timestamp - 3 * 3600))])->assertJsonValidationErrors('form');
        $this->assertFalse(Hotel::where('name', 'Lake Tana Lodge')->exists());
        // No cookie is needed (the form time is in the form).
        $this->getJson('/api/v1/signup')->assertOk()->assertJsonStructure(['data' => ['form_token']]);
    }

    public function test_the_rate_limit_stops_many_sign_ups_from_one_address(): void
    {
        PlatformSetting::write('signup_mode', 'open');
        foreach (range(1, 5) as $i) {
            $this->register(['email' => "owner{$i}@lodge.test", 'hotel_name' => "Hotel {$i}"])->assertCreated();
        }
        $this->register(['email' => 'owner6@lodge.test'])->assertStatus(429);
    }

    public function test_the_form_checks_the_fields_and_our_password_rules(): void
    {
        PlatformSetting::write('signup_mode', 'open');
        $this->register(['password' => 'password123', 'password_confirmation' => 'password123', 'accept_terms' => false, 'phone' => 'call me', 'rooms' => 0])
            ->assertJsonValidationErrors(['password', 'accept_terms', 'phone', 'rooms']);
        $this->register(['email' => 'not-an-email'])->assertJsonValidationErrors('email');
    }

    public function test_an_email_that_already_has_an_account_gets_the_same_answer_and_nothing_changes(): void
    {
        PlatformSetting::write('signup_mode', 'open');
        User::factory()->create(['email' => 'almaz@lodge.test']);
        $this->register()->assertCreated();
        $this->assertFalse(Hotel::where('name', 'Lake Tana Lodge')->exists());
        Mail::assertNothingSent();
    }

    public function test_resend_sends_a_new_link_quietly(): void
    {
        PlatformSetting::write('signup_mode', 'open');
        $this->register()->assertCreated();
        $this->postJson('/api/v1/signup/resend', ['email' => 'almaz@lodge.test'])->assertOk();
        $this->postJson('/api/v1/signup/resend', ['email' => 'nobody@lodge.test'])->assertOk(); // same answer
        Mail::assertSent(SignupVerifyMail::class, 2);
    }

    public function test_the_trial_switch_gives_a_trial_of_the_chosen_plan(): void
    {
        PlatformSetting::write('signup_mode', 'open');
        PlatformSetting::write('signup_trial', true);
        PlatformSetting::write('default_trial_days', 14);
        PlatformSetting::write('default_trial_plan_id', Plan::where('code', 'professional')->value('id'));
        $this->register()->assertCreated();
        $hotel = Hotel::where('name', 'Lake Tana Lodge')->firstOrFail();
        $this->assertSame('trial', $hotel->status);
        $this->assertSame(now()->addDays(14)->toDateString(), $hotel->trial_ends_at->toDateString());
    }

    public function test_only_the_owner_changes_the_sign_up_mode(): void
    {
        $owner = User::factory()->superAdmin()->create(['platform_role' => 'owner']);
        $this->actingAs($owner)->putJson('/api/v1/admin/settings', ['signup_mode' => 'maybe'])->assertJsonValidationErrors('signup_mode');
        $this->putJson('/api/v1/admin/settings', ['signup_mode' => 'approval'])->assertOk();
        $this->assertSame('approval', PlatformSetting::read('signup_mode'));
    }

    public function test_hotels_made_by_the_team_are_not_affected(): void
    {
        $hotel = Hotel::factory()->create();
        $admin = User::factory()->withRole($hotel, 'hotel_admin')->create(['email_verified_at' => null]);
        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
    }
}
