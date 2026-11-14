<?php

namespace Tests\Feature\Auth;

use App\Mail\EmailChangeMail;
use App\Mail\SecurityNoticeMail;
use App\Models\PlatformSetting;
use App\Models\TelegramLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OwnerEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_changes_their_sign_in_email_with_their_password(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'taken@example.et']);
        $owner = User::factory()->superAdmin()->create(['email' => 'admin@hotelms.test']);
        $this->actingAs($owner);

        $this->putJson('/api/v1/me/email', ['email' => 'Me@Gmail.com', 'current_password' => 'wrong'])->assertJsonValidationErrors('current_password');
        $this->putJson('/api/v1/me/email', ['email' => 'taken@example.et', 'current_password' => 'password'])->assertJsonValidationErrors('email');
        // The new address must confirm (SECURITY_REPORT M7): until then the old one stays.
        $this->putJson('/api/v1/me/email', ['email' => 'Me@Gmail.com', 'current_password' => 'password'])->assertOk()
            ->assertJsonPath('email_change', 'pending')->assertJsonPath('user.email', 'admin@hotelms.test');
        $this->assertSame('me@gmail.com', $owner->fresh()->pending_email);
        Mail::assertSent(EmailChangeMail::class, function ($m) {
            preg_match('/token=([A-Za-z0-9]+)/', $m->link, $t);
            $this->postJson('/api/v1/auth/confirm-email', ['token' => $t[1]])->assertOk();

            return $m->hasTo('me@gmail.com');
        });
        $this->assertSame('me@gmail.com', $owner->fresh()->email);
        Mail::assertSent(SecurityNoticeMail::class, fn ($m) => $m->hasTo('admin@hotelms.test') && str_contains($m->text, 'me@gmail.com'));
    }

    public function test_nobody_else_changes_their_email_this_way(): void
    {
        $tech = User::factory()->superAdmin()->create(['platform_role' => 'tech', 'email' => 'tech@hotelms.test']);
        $this->actingAs($tech)->putJson('/api/v1/me/email', ['email' => 'x@gmail.com', 'current_password' => 'password'])->assertForbidden();
        $staff = User::factory()->create(['email' => 'staff@example.et']);
        $this->actingAs($staff)->putJson('/api/v1/me/email', ['email' => 'y@gmail.com', 'current_password' => 'password'])->assertForbidden();
        $this->assertSame('tech@hotelms.test', $tech->fresh()->email);
        $this->assertSame('staff@example.et', $staff->fresh()->email);
    }

    public function test_a_test_message_goes_to_my_linked_telegram(): void
    {
        $user = User::factory()->superAdmin()->create();
        $this->actingAs($user)->postJson('/api/v1/telegram/test')->assertJsonValidationErrors('telegram'); // not linked
        PlatformSetting::write('telegram_bot_token', '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ');
        TelegramLink::create(['user_id' => $user->id, 'hotel_id' => null, 'chat_id' => '555', 'chat_name' => 'Hashim', 'linked_at' => now()]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->postJson('/api/v1/telegram/test')->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && $r['chat_id'] === '555');
    }
}
