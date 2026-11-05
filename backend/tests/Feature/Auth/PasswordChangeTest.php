<?php

namespace Tests\Feature\Auth;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_temporary_password_blocks_everything_until_changed(): void
    {
        $user = User::factory()->forHotel(Hotel::factory()->create())->mustChangePassword()->create();

        $this->actingAs($user)->getJson('/api/v1/branches')
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');

        // These still work, so the user can fix it.
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.must_change_password', true);

        $this->putJson('/api/v1/me/password', [
            'current_password' => 'password',
            'password' => 'NewPass-2026',
            'password_confirmation' => 'NewPass-2026',
        ])->assertOk();

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('NewPass-2026', $user->password));

        $this->actingAs($user)->getJson('/api/v1/branches')->assertOk();
    }

    public function test_current_password_must_be_correct(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/v1/me/password', [
            'current_password' => 'wrong',
            'password' => 'NewPass-2026',
            'password_confirmation' => 'NewPass-2026',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }

    public function test_new_password_must_be_strong_and_different(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/v1/me/password', [
            'current_password' => 'password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->putJson('/api/v1/me/password', [
            'current_password' => 'password',
            'password' => 'password1',
            'password_confirmation' => 'password1',
        ])->assertOk();

        $this->putJson('/api/v1/me/password', [
            'current_password' => 'password1',
            'password' => 'password1',
            'password_confirmation' => 'password1',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }
}
