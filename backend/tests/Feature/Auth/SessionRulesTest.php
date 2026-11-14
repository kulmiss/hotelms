<?php

namespace Tests\Feature\Auth;

use App\Models\Branch;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The screen lock on the server and the 12-hour sign-in (SECURITY_REPORT M3, M4; owner Q3 14 Nov). */
class SessionRulesTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hotel = Hotel::factory()->create();
        Branch::factory()->for($this->hotel)->create();
    }

    private function signIn(User $user, bool $remember = false)
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'remember' => $remember])->assertOk();
    }

    public function test_a_locked_screen_is_locked_on_the_server_until_unlocked(): void
    {
        $staff = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $this->signIn($staff);
        $this->getJson('/api/v1/notifications')->assertOk();

        $this->postJson('/api/v1/auth/lock')->assertOk();
        $this->getJson('/api/v1/notifications')->assertStatus(423)->assertJsonPath('code', 'screen_locked');
        $this->getJson('/api/v1/guests')->assertStatus(423);
        $this->getJson('/api/v1/me')->assertOk(); // the lock screen shows the name

        $this->postJson('/api/v1/auth/unlock', ['password' => 'wrong'])->assertUnprocessable();
        $this->getJson('/api/v1/notifications')->assertStatus(423);
        $this->postJson('/api/v1/auth/unlock', ['password' => 'password'])->assertOk();
        $this->getJson('/api/v1/notifications')->assertOk();
    }

    public function test_signing_out_from_a_locked_screen_works(): void
    {
        $staff = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $this->signIn($staff);
        $this->postJson('/api/v1/auth/lock')->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertGuest('web');
    }

    public function test_a_sign_in_ends_after_12_hours(): void
    {
        $staff = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $this->signIn($staff);
        $this->travel(11)->hours();
        $this->getJson('/api/v1/me')->assertOk();
        $this->travel(2)->hours();
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'session_expired');
        $this->assertGuest('web');
    }

    public function test_remember_me_lasts_30_days_for_staff_and_is_ignored_for_admins(): void
    {
        $staff = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $cookie = collect($this->signIn($staff, true)->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
        $this->assertNotNull($cookie);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $cookie->getExpiresTime(), 120);
        $this->travel(13)->hours();
        $this->getJson('/api/v1/me')->assertOk(); // a remembered device stays signed in
        $this->postJson('/api/v1/auth/logout');

        $admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();
        $response = $this->signIn($admin, true);
        $this->assertNull(collect($response->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_')));
        $this->travel(13)->hours();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }
}
