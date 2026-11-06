<?php

namespace Tests\Feature\Admin;

use App\Models\Guest;
use App\Models\Hotel;
use App\Models\User;
use App\Services\Backup\Backups;
use App\Services\HotelDefaults;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/** Leaving hotels, "download all our data" and backups (decisions.md W7). */
class HotelLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    private Hotel $other;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hotel = Hotel::factory()->create(['name' => 'Sheger Hotel', 'status' => 'active']);
        $this->other = Hotel::factory()->create(['name' => 'Axum Inn', 'status' => 'active']);
        foreach ([$this->hotel, $this->other] as $h) {
            app(HotelDefaults::class)->apply($h);
            app(TenantContext::class)->runAs($h, fn () => Guest::create(['first_name' => 'Guest of '.$h->name, 'id_number' => 'ID-'.$h->id]));
        }
        $this->admin = User::factory()->withRole($this->hotel, 'hotel_admin')->create(['email' => 'admin@sheger.test']);
        User::factory()->withRole($this->other, 'hotel_admin')->create(['email' => 'admin@axum.test']);
    }

    public function test_a_closed_hotel_cannot_sign_in_can_reopen_and_is_deleted_after_90_days(): void
    {
        $owner = User::factory()->superAdmin()->create();
        $this->actingAs($owner)->postJson("/api/v1/admin/hotels/{$this->hotel->id}/close", ['reason' => 'Left', 'confirm_name' => 'wrong'])->assertUnprocessable();
        $this->postJson("/api/v1/admin/hotels/{$this->hotel->id}/close", ['reason' => 'Left', 'confirm_name' => 'sheger hotel'])->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => 'admin@sheger.test', 'password' => 'password'])->assertUnprocessable();
        $this->actingAs($owner)->getJson('/api/v1/admin/hotels?closed=1')->assertJsonPath('data.0.name', 'Sheger Hotel');

        $this->postJson("/api/v1/admin/hotels/{$this->hotel->id}/reopen")->assertOk();
        $this->assertSame('active', $this->admin->fresh()->status);
        $this->postJson("/api/v1/admin/hotels/{$this->hotel->id}/close", ['reason' => 'Left', 'confirm_name' => 'Sheger Hotel'])->assertOk();

        $this->travel(89)->days();
        $this->artisan('hotelms:purge-closed-hotels')->assertSuccessful();
        $this->assertTrue(Hotel::withTrashed()->whereKey($this->hotel->id)->exists());

        // Its files go too (payment proofs, ID photos); other hotels' files stay.
        Storage::fake('local');
        Storage::disk('local')->put("payment-proofs/{$this->hotel->id}/a.jpg", 'x');
        Storage::disk('local')->put("guest-ids/{$this->hotel->id}/b.bin", 'x');
        Storage::disk('local')->put("guest-ids/{$this->other->id}/c.bin", 'x');

        $this->travel(2)->days();
        $this->artisan('hotelms:purge-closed-hotels')->assertSuccessful();
        $this->assertFalse(Storage::disk('local')->exists("payment-proofs/{$this->hotel->id}/a.jpg"));
        $this->assertFalse(Storage::disk('local')->exists("guest-ids/{$this->hotel->id}/b.bin"));
        $this->assertTrue(Storage::disk('local')->exists("guest-ids/{$this->other->id}/c.bin"));
        $this->assertFalse(Hotel::withTrashed()->whereKey($this->hotel->id)->exists());
        $this->assertSame(0, DB::table('users')->where('hotel_id', $this->hotel->id)->count());
        $this->assertSame(0, DB::table('guests')->where('hotel_id', $this->hotel->id)->count());
        $this->assertSame(0, DB::table('roles')->where('hotel_id', $this->hotel->id)->count());
        // The other hotel is untouched.
        $this->assertSame(1, DB::table('guests')->where('hotel_id', $this->other->id)->count());
        $this->assertTrue(User::where('email', 'admin@axum.test')->exists());
        $this->assertGreaterThan(0, DB::table('role_permissions')->whereIn('role_id', DB::table('roles')->where('hotel_id', $this->other->id)->select('id'))->count());
    }

    public function test_hotel_admin_downloads_all_their_data_and_only_theirs(): void
    {
        $response = $this->actingAs($this->admin)->get('/api/v1/settings/export')->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file));
        $guests = (string) $zip->getFromName('guests.csv');
        $users = (string) $zip->getFromName('users.csv');
        $zip->close();

        $this->assertStringContainsString('Guest of Sheger Hotel', $guests);
        $this->assertStringContainsString('ID-'.$this->hotel->id, $guests); // decrypted
        $this->assertStringNotContainsString('Axum', $guests);
        $this->assertStringContainsString('admin@sheger.test', $users);
        $header = str_getcsv(strtok(ltrim($users, "\xEF\xBB\xBF"), "\n"), ',', '"', '');
        $this->assertNotContains('password', $header);
        $this->assertNotContains('two_factor_secret', $header);
        $this->assertContains('email', $header);
        $this->assertStringNotContainsString('admin@axum.test', $users);

        $staff = User::factory()->withRole($this->hotel, 'branch_manager')->create();
        $this->actingAs($staff)->get('/api/v1/settings/export')->assertForbidden();
    }

    public function test_backups_are_complete_and_checked(): void
    {
        $dir = storage_path('framework/testing/backups');
        File::deleteDirectory($dir);
        config(['hotelms.backups.path' => $dir, 'hotelms.backups.copy_path' => $dir.'-copy']);

        $result = app(Backups::class)->make('daily');
        $this->assertFileExists($result['file']);
        $this->assertTrue($result['copied']);
        $this->assertGreaterThan(10, $result['tables']);
        $this->artisan('hotelms:backup --check')->assertSuccessful();

        $sql = gzdecode((string) file_get_contents($result['file']));
        $this->assertStringContainsString('CREATE TABLE `guests`', $sql);
        $this->assertStringNotContainsString('CREATE TABLE `sessions`', $sql);

        File::put($result['file'], gzencode('-- table guests')); // cut off
        $this->artisan('hotelms:backup --check')->assertFailed();
        File::deleteDirectory($dir);
        File::deleteDirectory($dir.'-copy');
    }

    public function test_uploaded_photos_are_mirrored_with_the_daily_backup(): void
    {
        $dir = storage_path('framework/testing/backups-files');
        $source = storage_path('app/private/payment-proofs/99999');
        File::deleteDirectory($dir);
        config(['hotelms.backups.path' => $dir, 'hotelms.backups.copy_path' => $dir.'-copy']);
        File::ensureDirectoryExists($source);
        File::put("{$source}/slip.jpg", 'photo');

        $this->assertSame(['copied' => 2, 'removed' => 0], app(Backups::class)->syncFiles()); // backup folder + second copy
        $this->assertFileExists("{$dir}/files/payment-proofs/99999/slip.jpg");
        $this->assertFileExists("{$dir}-copy/files/payment-proofs/99999/slip.jpg");
        $this->assertSame(['copied' => 0, 'removed' => 0], app(Backups::class)->syncFiles()); // nothing new

        File::delete("{$source}/slip.jpg"); // deleted (e.g. the hotel's "delete after" rule): gone from backups too
        $this->assertSame(['copied' => 0, 'removed' => 2], app(Backups::class)->syncFiles());

        File::deleteDirectory($source);
        File::deleteDirectory($dir);
        File::deleteDirectory($dir.'-copy');
    }
}
