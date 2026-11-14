<?php

namespace Tests\Feature\Auth;

use App\Mail\EmailChangeMail;
use App\Mail\SecurityNoticeMail;
use App\Models\Branch;
use App\Models\Hotel;
use App\Models\User;
use App\Services\Security\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** "Was this you?" emails, confirmed email changes, temporary passwords that run out (SECURITY_REPORT M7, L6). */
class SecurityNoticesTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->hotel = Hotel::factory()->create();
        Branch::factory()->for($this->hotel)->create();
    }

    private function notice(string $to, string $title): void
    {
        Mail::assertSent(SecurityNoticeMail::class, fn ($m) => $m->hasTo($to) && $m->title === $title);
    }

    public function test_a_password_change_is_told_to_the_person(): void
    {
        $staff = User::factory()->withRole($this->hotel, 'receptionist')->create(['email' => 'hana@sheger.test']);
        $this->actingAs($staff)->putJson('/api/v1/me/password', ['current_password' => 'password', 'password' => 'Blue-Nile-Kettle-42', 'password_confirmation' => 'Blue-Nile-Kettle-42'])->assertOk();
        $this->notice('hana@sheger.test', 'Your password was changed');
    }

    public function test_recovery_codes_and_turning_two_step_off_are_told(): void
    {
        $staff = User::factory()->withRole($this->hotel, 'receptionist')->create(['email' => 'hana@sheger.test', 'two_factor_method' => 'email', 'two_factor_confirmed_at' => now()]);
        app(TwoFactor::class)->newRecoveryCodes($staff);
        $this->actingAs($staff)->postJson('/api/v1/me/security/recovery-codes', ['password' => 'password'])->assertOk();
        $this->notice('hana@sheger.test', 'New recovery codes');
        $this->postJson('/api/v1/me/security/two-factor/disable', ['password' => 'password'])->assertOk();
        $this->notice('hana@sheger.test', 'Two-step sign-in was turned off');
    }

    public function test_a_manager_changing_a_staff_email_needs_the_new_address_to_confirm(): void
    {
        $admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();
        $staff = User::factory()->withRole($this->hotel, 'receptionist')->create(['email' => 'hana@sheger.test']);
        $staff->branches()->sync(\DB::table('branches')->where('hotel_id', $this->hotel->id)->pluck('id'));
        $input = ['name' => $staff->name, 'email' => 'Hana.New@Sheger.test', 'phone' => null, 'role_id' => $staff->role_id, 'branch_ids' => \DB::table('branches')->where('hotel_id', $this->hotel->id)->pluck('id')->all()];

        $this->actingAs($admin)->putJson("/api/v1/staff/{$staff->id}", $input)->assertOk()
            ->assertJsonPath('email_change', 'pending')->assertJsonPath('data.email', 'hana@sheger.test')->assertJsonPath('data.pending_email', 'hana.new@sheger.test');
        $link = null;
        Mail::assertSent(EmailChangeMail::class, function ($m) use (&$link, $admin) {
            $link = $m->link;

            return $m->hasTo('hana.new@sheger.test') && $m->by === $admin->name;
        });
        preg_match('/token=([A-Za-z0-9]+)/', (string) $link, $t);

        $this->postJson('/api/v1/auth/confirm-email', ['token' => 'x'.substr($t[1], 1)])->assertJsonValidationErrors('token'); // wrong
        $this->postJson('/api/v1/auth/confirm-email', ['token' => $t[1]])->assertOk()->assertJsonPath('email', 'hana.new@sheger.test');
        $this->assertSame('hana.new@sheger.test', $staff->fresh()->email);
        $this->notice('hana@sheger.test', 'Your sign-in email was changed'); // to the OLD address
        $this->postJson('/api/v1/auth/confirm-email', ['token' => $t[1]])->assertJsonValidationErrors('token'); // used up
    }

    public function test_a_link_works_for_24_hours_only(): void
    {
        $owner = User::factory()->superAdmin()->create(['email' => 'owner@hotelms.test']);
        $this->actingAs($owner)->putJson('/api/v1/me/email', ['email' => 'new@hotelms.test', 'current_password' => 'password'])->assertOk();
        $token = null;
        Mail::assertSent(EmailChangeMail::class, function ($m) use (&$token) {
            preg_match('/token=([A-Za-z0-9]+)/', $m->link, $t);
            $token = $t[1];

            return true;
        });
        $this->travel(25)->hours();
        $this->postJson('/api/v1/auth/confirm-email', ['token' => $token])->assertJsonValidationErrors('token');
        $this->assertSame('owner@hotelms.test', $owner->fresh()->email);
    }

    public function test_a_demo_hotel_changes_at_once_and_sends_nothing(): void
    {
        $demo = Hotel::factory()->create(['is_demo' => true]);
        Branch::factory()->for($demo)->create();
        $admin = User::factory()->withRole($demo, 'hotel_admin')->create();
        $staff = User::factory()->withRole($demo, 'receptionist')->create();
        $this->actingAs($admin)->putJson("/api/v1/staff/{$staff->id}", ['name' => $staff->name, 'email' => 'x@demo.test', 'phone' => null, 'role_id' => $staff->role_id, 'branch_ids' => \DB::table('branches')->where('hotel_id', $demo->id)->pluck('id')->all()])
            ->assertOk()->assertJsonPath('email_change', 'changed')->assertJsonPath('data.email', 'x@demo.test');
        Mail::assertNotSent(EmailChangeMail::class);
    }

    public function test_a_temporary_password_works_for_7_days(): void
    {
        $admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create();
        $staff = User::factory()->withRole($this->hotel, 'receptionist')->create(['email' => 'new@sheger.test']);
        $staff->forceFill(['must_change_password' => true])->save();
        $this->assertTrue($staff->fresh()->temporary_password_expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));

        $this->travel(8)->days();
        $this->postJson('/api/v1/auth/login', ['email' => 'new@sheger.test', 'password' => 'password'])
            ->assertJsonPath('errors.email.0', 'This temporary password has expired (it works for 7 days). Ask your manager to send you a new one.');

        $reset = $this->actingAs($admin)->postJson("/api/v1/staff/{$staff->id}/reset-password", ['send_email' => false])->assertOk()->json('temporary_password');
        $this->postJson('/api/v1/auth/logout');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'new@sheger.test', 'password' => $reset])->assertOk();
    }
}
