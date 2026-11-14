<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Backup\BackupCipher;
use App\Services\Backup\Backups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/** Backups are encrypted with their own key (SECURITY_REPORT H1, owner 14 Nov). */
class EncryptedBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/enc-backups');
        File::deleteDirectory($this->dir);
        File::deleteDirectory($this->dir.'-copy');
        config(['hotelms.backups.path' => $this->dir, 'hotelms.backups.copy_path' => $this->dir.'-copy', 'hotelms.backups.key' => BackupCipher::newKey()]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        File::deleteDirectory($this->dir.'-copy');
        File::deleteDirectory(storage_path('app/private/payment-proofs/88888'));
        parent::tearDown();
    }

    public function test_a_backup_is_encrypted_checked_and_can_be_decrypted_with_the_key_only(): void
    {
        User::factory()->create(['name' => 'Zebra Marker Person']);
        $r = app(Backups::class)->make('daily');

        $this->assertStringEndsWith('.sql.gz.enc', $r['file']);
        $this->assertFileExists($this->dir.'-copy/daily/'.basename($r['file']));
        $raw = (string) file_get_contents($r['file']);
        $this->assertStringStartsWith(BackupCipher::MAGIC, $raw);
        $this->assertFalse(@gzdecode(substr($raw, strlen(BackupCipher::MAGIC))), 'not a readable gzip');
        $this->assertStringNotContainsString('Zebra Marker Person', $raw);
        $this->artisan('hotelms:backup --check')->assertSuccessful();

        $out = $this->dir.'/plain.sql';
        $this->artisan('hotelms:backup', ['--decrypt' => $r['file'], '--to' => $out])->assertSuccessful();
        $sql = (string) file_get_contents($out);
        $this->assertStringContainsString('CREATE TABLE `guests`', $sql);
        $this->assertStringContainsString('Zebra Marker Person', $sql);
        $this->assertStringContainsString('-- end of backup', $sql);

        // Another key cannot open it.
        config(['hotelms.backups.key' => BackupCipher::newKey()]);
        $check = app(Backups::class)->check('daily');
        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('wrong BACKUP_KEY', (string) $check['problem']);
    }

    public function test_a_changed_or_cut_off_backup_fails_the_check(): void
    {
        $r = app(Backups::class)->make('daily');
        $raw = (string) file_get_contents($r['file']);

        File::put($r['file'], substr($raw, 0, -10)); // cut off
        $this->artisan('hotelms:backup --check')->assertFailed();

        $raw[100] = $raw[100] === 'a' ? 'b' : 'a'; // one byte changed
        File::put($r['file'], $raw);
        $this->assertFalse(app(Backups::class)->check('daily')['ok']);
    }

    public function test_a_live_server_makes_no_backup_without_a_key(): void
    {
        config(['hotelms.backups.key' => null]);
        $this->app['env'] = 'production';
        try {
            app(Backups::class)->make('daily');
            $this->fail('A backup was made without a key.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('BACKUP_KEY is missing', $e->getMessage());
        }
        $this->assertSame([], app(Backups::class)->files('daily'));
    }

    public function test_older_plain_backups_can_still_be_checked(): void
    {
        config(['hotelms.backups.key' => null]); // development without a key: plain gzip, as before
        $plain = app(Backups::class)->make('daily');
        $this->assertStringEndsWith('.sql.gz', $plain['file']);

        config(['hotelms.backups.key' => BackupCipher::newKey()]);
        $this->assertTrue(app(Backups::class)->check('daily')['ok']);
    }

    public function test_uploaded_files_are_mirrored_encrypted(): void
    {
        $source = storage_path('app/private/payment-proofs/88888');
        File::ensureDirectoryExists($source);
        File::put("{$source}/slip.jpg", 'secret photo bytes');
        File::ensureDirectoryExists($this->dir.'/files/payment-proofs/88888');
        File::put($this->dir.'/files/payment-proofs/88888/slip.jpg', 'secret photo bytes'); // a plain copy from before

        app(Backups::class)->syncFiles();

        $copy = $this->dir.'/files/payment-proofs/88888/slip.jpg.enc';
        $this->assertFileExists($copy);
        $this->assertFileDoesNotExist($this->dir.'/files/payment-proofs/88888/slip.jpg'); // the plain copy is gone
        $this->assertStringNotContainsString('secret photo bytes', (string) file_get_contents($copy));
        $this->assertSame('secret photo bytes', implode('', iterator_to_array(BackupCipher::read($copy, BackupCipher::key()), false)));
        $this->assertSame(['copied' => 0, 'removed' => 0], app(Backups::class)->syncFiles()); // nothing new
    }

    public function test_big_files_round_trip_in_many_chunks(): void
    {
        $key = BackupCipher::key();
        $file = $this->dir.'/big.enc';
        File::ensureDirectoryExists($this->dir);
        $w = BackupCipher::create($file, $key);
        $expected = '';
        for ($i = 0; $i < 3000; $i++) {
            $line = "INSERT INTO t VALUES ({$i}, '".bin2hex(random_bytes(40))."');\n"; // not compressible: many chunks
            $w->write($line);
            $expected .= $line;
        }
        $w->close();

        $this->assertSame($expected, implode('', iterator_to_array(BackupCipher::lines($file, $key), false)));
    }

    public function test_the_key_command_prints_a_valid_key(): void
    {
        $this->artisan('hotelms:backup-key')->expectsOutputToContain('BACKUP_KEY=base64:')->assertSuccessful();
        $this->assertSame(32, strlen((string) base64_decode(substr(BackupCipher::newKey(), 7))));
    }
}
