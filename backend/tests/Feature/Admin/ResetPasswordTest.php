<?php

namespace Tests\Feature\Admin;

use App\Mail\TemporaryPassword;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_can_give_a_hotel_user_a_new_temporary_password(): void
    {
        Mail::fake();
        $hotel = Hotel::factory()->create();
        $user = User::factory()->forHotel($hotel)->create();

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson("/api/v1/admin/hotels/{$hotel->id}/users/{$user->id}/reset-password", ['send_email' => true])
            ->assertOk()
            ->assertJsonPath('email_sent', true);

        $password = $response->json('temporary_password');
        $user->refresh();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertTrue($user->must_change_password);
        Mail::assertSent(TemporaryPassword::class, fn ($mail) => $mail->hasTo($user->email) && $mail->temporaryPassword === $password);
    }

    public function test_email_can_be_skipped(): void
    {
        Mail::fake();
        $hotel = Hotel::factory()->create();
        $user = User::factory()->forHotel($hotel)->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson("/api/v1/admin/hotels/{$hotel->id}/users/{$user->id}/reset-password", ['send_email' => false])
            ->assertOk()
            ->assertJsonPath('email_sent', false);

        Mail::assertNothingSent();
    }

    public function test_user_must_belong_to_that_hotel(): void
    {
        $hotel = Hotel::factory()->create();
        $stranger = User::factory()->forHotel(Hotel::factory()->create())->create();

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postJson("/api/v1/admin/hotels/{$hotel->id}/users/{$stranger->id}/reset-password")
            ->assertNotFound();
    }

    public function test_finance_cannot_reset_passwords(): void
    {
        $hotel = Hotel::factory()->create();
        $user = User::factory()->forHotel($hotel)->create();

        $this->actingAs(User::factory()->platformRole('finance')->create())
            ->postJson("/api/v1/admin/hotels/{$hotel->id}/users/{$user->id}/reset-password")
            ->assertForbidden();
    }

    public function test_users_can_edit_their_own_name_and_phone(): void
    {
        $user = User::factory()->forHotel(Hotel::factory()->create())->create();

        $this->actingAs($user)->putJson('/api/v1/me', ['name' => 'Meron T.', 'phone' => '+251911223344'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Meron T.')
            ->assertJsonPath('user.phone', '+251911223344');
    }
}
