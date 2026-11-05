<?php

namespace Tests\Feature\Auth;

use App\Mail\NewDeviceMail;
use App\Mail\SecurityCodeMail;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\HotelDefaults;
use App\Services\HotelSettings;
use App\Services\Modules\Attendance;
use App\Services\Security\Devices;
use App\Services\Security\TwoFactor;
use App\Support\Tenancy\TenantContext;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->hotel = Hotel::factory()->create(['status' => 'active']);
        app(HotelDefaults::class)->apply($this->hotel);
        $this->admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create(['email' => 'admin@sheger.test']);
        $this->staff = User::factory()->withRole($this->hotel, 'receptionist')->create(['email' => 'hana@sheger.test']);
    }

    private function inHotel(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->hotel, $fn);
    }

    /** A new request as a new browser tab would see it (Sanctum caches the user per guard). */
    private function freshGuards(): void
    {
        config(['auth.defaults.guard' => 'web']);
        $this->app['auth']->forgetGuards();
    }

    private function login(string $email, ?string $device = null): TestResponse
    {
        $this->freshGuards();
        auth()->guard('web')->logout();
        $this->freshGuards();
        $req = $device ? $this->withCookie(Devices::COOKIE, $device) : $this;

        return $req->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'password']);
    }

    private function useApp(User $user): string
    {
        $secret = Totp::newSecret();
        $user->forceFill(['two_factor_method' => 'app', 'two_factor_secret' => Crypt::encryptString($secret), 'two_factor_confirmed_at' => now()])->save();

        return $secret;
    }

    public function test_totp_matches_the_rfc_test_vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('287082', Totp::code($secret, intdiv(59, 30)));
        $this->assertSame('081804', Totp::code($secret, intdiv(1111111109, 30)));
        $this->assertNotNull(Totp::match($secret, '081804', 1111111109 + 30)); // one step of clock drift is fine
        $this->assertNull(Totp::match($secret, '081804', 1111111109 + 120));
    }

    public function test_sign_in_with_an_authenticator_app_code(): void
    {
        $secret = $this->useApp($this->staff);

        $this->login('hana@sheger.test')->assertOk()->assertJsonPath('two_factor', 'app');
        $this->assertGuest('web');
        $this->getJson('/api/v1/me')->assertUnauthorized();

        $this->postJson('/api/v1/auth/two-factor', ['code' => '000000'])->assertUnprocessable();
        $code = Totp::code($secret, intdiv(time(), 30));
        $this->postJson('/api/v1/auth/two-factor', ['code' => $code])->assertOk();
        $this->freshGuards();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('security.two_factor', 'app');

        // The same code cannot be used again.
        $this->login('hana@sheger.test')->assertJsonPath('two_factor', 'app');
        $this->postJson('/api/v1/auth/two-factor', ['code' => $code])->assertUnprocessable();
    }

    public function test_sign_in_with_an_email_code_and_a_recovery_code(): void
    {
        $this->staff->forceFill(['two_factor_method' => 'email', 'two_factor_confirmed_at' => now()])->save();
        $codes = app(TwoFactor::class)->newRecoveryCodes($this->staff);

        $this->login('hana@sheger.test')->assertOk()->assertJsonPath('two_factor', 'email')->assertJsonPath('email', 'ha••@sheger.test');
        $sent = null;
        Mail::assertSent(SecurityCodeMail::class, function (SecurityCodeMail $m) use (&$sent) {
            $sent = $m->code;

            return $m->hasTo('hana@sheger.test');
        });
        $this->postJson('/api/v1/auth/two-factor', ['code' => $sent])->assertOk();

        $this->login('hana@sheger.test');
        $this->postJson('/api/v1/auth/two-factor', ['code' => $codes[0]])->assertOk();
        $this->login('hana@sheger.test');
        $this->postJson('/api/v1/auth/two-factor', ['code' => $codes[0]])->assertUnprocessable(); // used up
    }

    public function test_admins_must_set_up_two_step_sign_in_when_the_switch_is_on(): void
    {
        PlatformSetting::write('two_factor_admins', true);
        $this->actingAs($this->admin);
        $this->getJson('/api/v1/branches')->assertForbidden()->assertJsonPath('code', 'two_factor_setup_required');
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('security.two_factor_setup_required', true);

        $this->postJson('/api/v1/me/security/two-factor', ['method' => 'app', 'password' => 'wrong'])->assertUnprocessable();
        $secret = $this->postJson('/api/v1/me/security/two-factor', ['method' => 'app', 'password' => 'password'])->assertOk()->json('data.secret');
        $this->postJson('/api/v1/me/security/two-factor/confirm', ['code' => Totp::code($secret, intdiv(time(), 30))])->assertOk()->assertJsonCount(8, 'recovery_codes');
        $this->getJson('/api/v1/branches')->assertOk();
        $this->postJson('/api/v1/me/security/two-factor/disable', ['password' => 'password'])->assertUnprocessable(); // required: cannot turn off

        // Staff are not required unless the hotel asks for it.
        $this->actingAs($this->staff)->getJson('/api/v1/branches')->assertOk();
        $this->inHotel(fn () => app(HotelSettings::class)->put(['require_two_factor_staff' => true]));
        $this->getJson('/api/v1/branches')->assertForbidden();
    }

    public function test_hotel_admin_is_told_about_new_devices_and_can_sign_them_out(): void
    {
        $this->login('hana@sheger.test', str_repeat('a', 40))->assertOk();
        Mail::assertNothingSent(); // first ever sign-in is not "new"
        $this->login('hana@sheger.test', str_repeat('b', 40))->assertOk();
        Mail::assertSent(NewDeviceMail::class, fn ($m) => $m->hasTo('admin@sheger.test') && ! $m->waiting);
        $this->getJson('/api/v1/me')->assertOk();

        $device = UserDevice::where('user_id', $this->staff->id)->where('token_hash', Devices::hash(str_repeat('b', 40)))->firstOrFail();
        $staffSession = $this->app['session']->all();
        $this->actingAs($this->admin)->getJson('/api/v1/devices')->assertOk()->assertJsonCount(2, 'data');
        $this->postJson("/api/v1/devices/{$device->id}/sign-out")->assertOk();

        // Back on Hana's device: signed out at the next request.
        auth()->guard('web')->logout();
        $this->actingAs($this->staff)->withSession($staffSession)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'device_signed_out');
    }

    public function test_staff_only_on_approved_devices_when_switched_on(): void
    {
        $this->inHotel(fn () => app(HotelSettings::class)->put(['approved_devices_only' => true]));
        $ipad = str_repeat('c', 40);

        $this->login('hana@sheger.test', $ipad)->assertForbidden()->assertJsonValidationErrors('email');
        $this->assertGuest('web');
        Mail::assertSent(NewDeviceMail::class, fn ($m) => $m->waiting);
        $this->login('admin@sheger.test', str_repeat('d', 40))->assertOk(); // admins are never blocked

        $pending = UserDevice::where('status', 'pending')->firstOrFail();
        $this->actingAs($this->admin)->postJson("/api/v1/devices/{$pending->id}/approve")->assertOk();
        $this->login('hana@sheger.test', $ipad)->assertOk();

        // The approved iPad works for other staff too.
        $other = User::factory()->withRole($this->hotel, 'receptionist')->create(['email' => 'sara@sheger.test']);
        $this->login('sara@sheger.test', $ipad)->assertOk();
        $this->login('sara@sheger.test', str_repeat('e', 40))->assertForbidden();
        $this->assertNotNull($other);
    }

    public function test_devices_of_another_hotel_cannot_be_touched(): void
    {
        $this->login('hana@sheger.test', str_repeat('a', 40))->assertOk();
        $device = UserDevice::where('user_id', $this->staff->id)->firstOrFail();

        $other = Hotel::factory()->create(['status' => 'active']);
        app(HotelDefaults::class)->apply($other);
        $otherAdmin = User::factory()->withRole($other, 'hotel_admin')->create();
        $this->actingAs($otherAdmin)->getJson('/api/v1/devices')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/devices/{$device->id}/sign-out")->assertNotFound();
        $this->assertSame('active', $device->fresh()->status);
    }

    public function test_staff_take_over_a_shared_device_with_their_pin(): void
    {
        $sara = User::factory()->withRole($this->hotel, 'receptionist')->create(['name' => 'Sara']);
        $this->inHotel(function () use ($sara) {
            app(Attendance::class)->setPin($sara, '4821');
            app(Attendance::class)->setPin($this->admin, '1111');
        });
        $this->login('hana@sheger.test')->assertOk();

        $this->postJson('/api/v1/auth/pin-switch', ['pin' => '9999'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/pin-switch', ['pin' => '1111'])->assertUnprocessable(); // not for admins
        $this->postJson('/api/v1/auth/pin-switch', ['pin' => '4821'])->assertOk();
        $this->freshGuards();
        $this->getJson('/api/v1/me')->assertJsonPath('user.name', 'Sara');
        $this->assertTrue(DB::table('activity_logs')->where('event', 'security.pin_switch')->exists());

        $this->postJson('/api/v1/auth/unlock', ['password' => 'nope'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/unlock', ['password' => 'password'])->assertOk();
    }

    public function test_guest_id_numbers_and_notes_are_encrypted_but_still_found(): void
    {
        $this->actingAs($this->admin);
        $id = $this->postJson('/api/v1/guests', ['first_name' => 'Abebe', 'id_type' => 'passport', 'id_number' => 'EP 12345', 'notes' => 'Allergic to nuts'])->assertCreated()->json('data.id');

        $raw = DB::table('guests')->where('id', $id)->first();
        $this->assertStringNotContainsString('EP', (string) $raw->id_number);
        $this->assertStringNotContainsString('nuts', (string) $raw->notes);
        $this->assertSame('EP 12345', $this->inHotel(fn () => Guest::find($id))->id_number);

        $this->getJson('/api/v1/guests?q=ep12345')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->postJson('/api/v1/guests', ['first_name' => 'Abebe B', 'id_number' => 'EP12345'])->assertCreated()->assertJsonPath('possible_duplicate.id', $id);
        $log = (string) DB::table('activity_logs')->where('subject_type', 'Guest')->where('subject_id', $id)->value('properties');
        $this->assertStringContainsString('id_number', $log);
        $this->assertStringNotContainsString('12345', $log);
        $this->assertStringNotContainsString('nuts', $log);
    }
}
