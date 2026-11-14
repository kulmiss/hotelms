<?php

namespace Tests\Feature\Auth;

use App\Mail\SecurityNoticeMail;
use App\Models\Hotel;
use App\Models\User;
use App\Support\PasswordRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Brute force per account, common passwords, weak-password note (SECURITY_REPORT M2, L1; owner Q2 14 Nov). */
class SignInHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function attempt(string $email, string $password, int $ip)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$ip}"])->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    public function test_ten_wrong_passwords_from_many_addresses_pause_the_account_and_tell_the_person(): void
    {
        Mail::fake();
        $hotel = Hotel::factory()->create();
        User::factory()->forHotel($hotel)->create(['email' => 'abebe@sheger.test']);

        for ($i = 1; $i <= 10; $i++) {
            $this->attempt('abebe@sheger.test', 'wrong-'.$i, $i)->assertUnprocessable()->assertJsonPath('errors.email.0', __('auth.failed'));
        }
        // Even the right password waits now, from any address.
        $this->attempt('abebe@sheger.test', 'password', 99)->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Too many wrong passwords for this email. Try again in 15 minutes, or use Forgot password.');
        $this->assertGuest('web');
        Mail::assertSent(SecurityNoticeMail::class, fn ($m) => $m->hasTo('abebe@sheger.test') && str_contains($m->text, '15 minutes'));
        $this->assertDatabaseHas('watch_events', ['kind' => 'login_locked']);

        $this->travel(16)->minutes();
        $this->attempt('abebe@sheger.test', 'password', 99)->assertOk();
    }

    public function test_a_second_pause_the_same_day_is_longer(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'sara@sheger.test']);
        foreach ([15, 30] as $minutes) {
            for ($i = 1; $i <= 10; $i++) {
                $this->attempt('sara@sheger.test', 'nope-'.$i, $i + $minutes);
            }
            $this->attempt('sara@sheger.test', 'password', 200)->assertJsonPath('errors.email.0', "Too many wrong passwords for this email. Try again in {$minutes} minutes, or use Forgot password.");
            $this->travel($minutes + 1)->minutes();
        }
    }

    public function test_an_email_without_account_answers_exactly_the_same(): void
    {
        Mail::fake();
        for ($i = 1; $i <= 10; $i++) {
            $this->attempt('nobody@sheger.test', 'wrong-'.$i, $i)->assertJsonPath('errors.email.0', __('auth.failed'));
        }
        $this->attempt('nobody@sheger.test', 'x', 50)->assertJsonPath('errors.email.0', 'Too many wrong passwords for this email. Try again in 15 minutes, or use Forgot password.');
        Mail::assertNothingSent();
    }

    public function test_a_right_password_resets_the_count(): void
    {
        $user = User::factory()->create(['email' => 'kebede@sheger.test']);
        for ($i = 1; $i <= 9; $i++) {
            $this->attempt('kebede@sheger.test', 'wrong-'.$i, $i);
        }
        $this->attempt('kebede@sheger.test', 'password', 20)->assertOk();
        $this->postJson('/api/v1/auth/logout');
        for ($i = 1; $i <= 9; $i++) {
            $this->attempt('kebede@sheger.test', 'wrong-'.$i, $i + 30)->assertJsonPath('errors.email.0', __('auth.failed'));
        }
        $this->attempt('kebede@sheger.test', 'password', 21)->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_new_passwords_follow_the_stricter_rules_and_old_weak_ones_still_work_with_a_note(): void
    {
        $user = User::factory()->create(['email' => 'abebe@sheger.test', 'password' => 'password']); // old and weak
        $this->postJson('/api/v1/auth/login', ['email' => 'abebe@sheger.test', 'password' => 'password'])->assertOk();
        $this->getJson('/api/v1/me')->assertJsonPath('security.password_weak', true);

        $change = fn (string $new) => $this->putJson('/api/v1/me/password', ['current_password' => 'password', 'password' => $new, 'password_confirmation' => $new]);
        $change('Short12')->assertJsonValidationErrors('password'); // under 10
        $change('Sunshine2026!')->assertJsonValidationErrors('password'); // common word + numbers
        $change('Abebe2026')->assertJsonValidationErrors('password'); // too short anyway
        $change('abebe123456')->assertJsonValidationErrors('password'); // own email name + numbers
        $change('Password1234')->assertJsonValidationErrors('password');
        $change('Blue-Nile-Kettle-42')->assertOk();

        $this->getJson('/api/v1/me')->assertJsonPath('security.password_weak', false);
        $this->assertTrue(password_verify('Blue-Nile-Kettle-42', $user->fresh()->password));
    }

    public function test_the_common_password_check_is_quick_and_local(): void
    {
        $start = microtime(true);
        $this->assertTrue(PasswordRules::isCommon('qwerty123456'));
        $this->assertTrue(PasswordRules::isCommon('iloveyou2026'));
        $this->assertFalse(PasswordRules::isCommon('Abebe-Kebede-77', 'abebe@x.et'));
        $this->assertLessThan(0.5, microtime(true) - $start);
    }
}
