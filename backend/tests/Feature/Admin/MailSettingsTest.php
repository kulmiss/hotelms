<?php

namespace Tests\Feature\Admin;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Notify\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_sets_the_mail_server_in_the_app_with_the_password_kept_secret(): void
    {
        $owner = User::factory()->superAdmin()->create(['email' => 'owner@hotelms.test']);
        $this->actingAs($owner)->putJson('/api/v1/admin/settings', [
            'mail_host' => 'mail.example.et', 'mail_port' => 465, 'mail_encryption' => 'ssl',
            'mail_username' => 'no-reply@example.et', 'mail_password' => 'S3cret-pass', 'mail_from_address' => 'no-reply@example.et',
        ])->assertOk()->assertJsonPath('data.mail_password', '••••••••')->assertJsonPath('data.mail_host', 'mail.example.et');

        // Stored encrypted, never in the activity log.
        $raw = (string) DB::table('platform_settings')->where('key', 'mail_password')->value('value');
        $this->assertStringNotContainsString('S3cret-pass', $raw);
        $this->assertStringNotContainsString('S3cret-pass', (string) DB::table('activity_logs')->pluck('properties')->implode(' '));

        // Sending the masked value back keeps the password.
        $this->putJson('/api/v1/admin/settings', ['mail_password' => '••••••••', 'mail_port' => 465])->assertOk();
        MailSettings::apply();
        $this->assertSame('mail.example.et', config('mail.mailers.smtp.host'));
        $this->assertSame('S3cret-pass', config('mail.mailers.smtp.password'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('no-reply@example.et', config('mail.from.address'));

        Mail::fake();
        $this->postJson('/api/v1/admin/settings/test-email')->assertOk()->assertJsonPath('message', 'Sent to owner@hotelms.test. Check the inbox (and spam).');
        $this->postJson('/api/v1/admin/settings/test-email', ['to' => 'me@gmail.com'])->assertOk()->assertJsonPath('message', 'Sent to me@gmail.com. Check the inbox (and spam).');
        $this->postJson('/api/v1/admin/settings/test-email', ['to' => 'not-an-email'])->assertUnprocessable();
    }

    public function test_only_the_owner_may_change_or_test_the_mail_server(): void
    {
        $tech = User::factory()->superAdmin()->create(['platform_role' => 'tech']);
        PlatformSetting::write('mail_host', null);
        $this->actingAs($tech)->putJson('/api/v1/admin/settings', ['mail_host' => 'evil.example.com'])->assertForbidden();
        $this->postJson('/api/v1/admin/settings/test-email')->assertForbidden();
        $this->assertNull(PlatformSetting::read('mail_host'));
    }
}
