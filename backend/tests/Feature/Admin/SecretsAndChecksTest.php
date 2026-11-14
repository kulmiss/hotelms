<?php

namespace Tests\Feature\Admin;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Notify\Telegram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Secrets encrypted, no internal mail servers, production checks, no public version (SECURITY_REPORT M6, L5, L8, L9). */
class SecretsAndChecksTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_telegram_token_is_stored_encrypted_and_never_sent_back(): void
    {
        $owner = User::factory()->superAdmin()->create();
        $this->actingAs($owner)->putJson('/api/v1/admin/settings', ['telegram_bot_token' => '123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZabcd'])->assertOk()
            ->assertJsonPath('data.telegram_bot_token', '••••••abcd');
        $raw = (string) DB::table('platform_settings')->where('key', 'telegram_bot_token')->value('value');
        $this->assertStringNotContainsString('ABCDEFGHIJ', $raw);
        $this->assertSame('123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZabcd', PlatformSetting::secret('telegram_bot_token'));
        $this->assertTrue(app(Telegram::class)->enabled());

        // Sending the masked value back keeps it.
        $this->putJson('/api/v1/admin/settings', ['telegram_bot_token' => '••••••abcd'])->assertOk();
        $this->assertSame('123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZabcd', PlatformSetting::secret('telegram_bot_token'));
    }

    public function test_an_old_plain_token_is_encrypted_by_the_migration(): void
    {
        PlatformSetting::write('telegram_bot_token', '42:plain-token-from-before-xxxxxxxx');
        $migration = require database_path('migrations/2026_11_25_000003_encrypt_telegram_bot_token.php');
        $migration->up();
        $this->assertStringNotContainsString('plain-token', (string) DB::table('platform_settings')->where('key', 'telegram_bot_token')->value('value'));
        $this->assertSame('42:plain-token-from-before-xxxxxxxx', PlatformSetting::secret('telegram_bot_token'));
        $migration->down();
        $this->assertSame('42:plain-token-from-before-xxxxxxxx', PlatformSetting::read('telegram_bot_token'));
    }

    public function test_the_mail_server_cannot_be_inside_the_servers_network(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        foreach (['localhost', '127.0.0.1', '10.0.0.5', '192.168.1.10', '169.254.169.254', 'db.internal'] as $host) {
            $this->putJson('/api/v1/admin/settings', ['mail_host' => $host])->assertJsonValidationErrors('mail_host');
        }
        $this->putJson('/api/v1/admin/settings', ['mail_host' => '1.1.1.1'])->assertOk();

        config(['hotelms.mail_allow_private_host' => true]); // MAIL_ALLOW_PRIVATE_HOST=true: a mail server on the same machine
        $this->putJson('/api/v1/admin/settings', ['mail_host' => 'localhost'])->assertOk();
    }

    public function test_system_health_checks_the_live_server_settings(): void
    {
        config(['app.debug' => true, 'session.secure' => false, 'app.url' => 'http://hotel.example']);
        $this->actingAs(User::factory()->superAdmin()->create(['platform_role' => 'tech']));
        $s = $this->getJson('/api/v1/admin/system')->assertOk()->json('data.checks.settings');
        $this->assertFalse($s['ok']);
        $this->assertCount(4, $s['problems']); // debug, not production (testing), cookie, http

        config(['app.debug' => false, 'session.secure' => true, 'app.url' => 'https://hotel.example']);
        $this->app['env'] = 'production';
        $this->assertTrue($this->getJson('/api/v1/admin/system')->json('data.checks.settings.ok'));
    }

    public function test_the_public_health_check_does_not_show_the_version(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('version', null)->assertJsonPath('status', 'ok');
        $this->actingAs(User::factory()->superAdmin()->create(), 'web')->getJson('/api/v1/health')->assertJsonPath('version', config('app.version'));
    }
}
