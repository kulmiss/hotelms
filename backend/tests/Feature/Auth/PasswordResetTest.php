<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_a_link_that_works_once(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'meron@sheger.test']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@sheger.test'])->assertOk(); // same answer: no hint which emails exist
        Mail::assertNothingSent();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'meron@sheger.test'])->assertOk();
        $link = null;
        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $m) use (&$link) {
            $link = $m->link;

            return $m->hasTo('meron@sheger.test');
        });
        parse_str((string) parse_url((string) $link, PHP_URL_QUERY), $q);

        $new = ['token' => $q['token'], 'email' => 'meron@sheger.test', 'password' => 'Blue-Nile-Kettle-42', 'password_confirmation' => 'Blue-Nile-Kettle-42'];
        $this->postJson('/api/v1/auth/reset-password', ['token' => 'wrong'] + $new)->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/auth/reset-password', $new)->assertOk();
        $this->postJson('/api/v1/auth/reset-password', $new)->assertJsonValidationErrors('email'); // used up

        $this->postJson('/api/v1/auth/login', ['email' => 'meron@sheger.test', 'password' => 'Blue-Nile-Kettle-42'])->assertOk();
    }
}
