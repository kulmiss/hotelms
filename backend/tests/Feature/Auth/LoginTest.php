<?php

namespace Tests\Feature\Auth;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_sign_in_and_see_who_they_are(): void
    {
        $hotel = Hotel::factory()->create(['name' => 'Sheger Hotel']);
        $user = User::factory()->forHotel($hotel)->create(['email' => 'abebe@sheger.test']);

        $this->postJson('/api/v1/auth/login', ['email' => 'abebe@sheger.test', 'password' => 'password'])
            ->assertOk();

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'abebe@sheger.test')
            ->assertJsonPath('user.is_super_admin', false)
            ->assertJsonPath('hotel.name', 'Sheger Hotel');

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['email' => 'abebe@sheger.test']);

        $this->postJson('/api/v1/auth/login', ['email' => 'abebe@sheger.test', 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest('web');
    }

    public function test_unknown_email_is_rejected_with_the_same_message(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@sheger.test', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', __('auth.failed'));
    }

    public function test_disabled_account_cannot_sign_in(): void
    {
        User::factory()->disabled()->create(['email' => 'old@sheger.test']);

        $this->postJson('/api/v1/auth/login', ['email' => 'old@sheger.test', 'password' => 'password'])
            ->assertUnprocessable();

        $this->assertGuest('web');
    }

    public function test_too_many_attempts_are_slowed_down(): void
    {
        User::factory()->create(['email' => 'abebe@sheger.test']);

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/v1/auth/login', ['email' => 'abebe@sheger.test', 'password' => 'wrong'])
                ->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'abebe@sheger.test', 'password' => 'password'])
            ->assertTooManyRequests();
    }

    public function test_signing_out_ends_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_me_requires_sign_in(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }
}
