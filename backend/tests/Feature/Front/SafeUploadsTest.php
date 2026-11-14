<?php

namespace Tests\Feature\Front;

use App\Models\Branch;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\User;
use App\Services\HotelSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Uploaded pictures are cleaned and shown safely; full guest IDs only for check-in staff (SECURITY_REPORT L4, owner Q5). */
class SafeUploadsTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->hotel = Hotel::factory()->create();
        Branch::factory()->for($this->hotel)->create();
    }

    private function jpegWithComment(int $w = 40, int $h = 30): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        ob_start();
        imagejpeg($img);
        $jpeg = (string) ob_get_clean();
        // Hidden text after the picture data (a "polyglot" file): it must not survive.
        $path = tempnam(sys_get_temp_dir(), 'img').'.jpg';
        file_put_contents($path, $jpeg.'<script>alert(1)</script>SECRET-GPS-48.1N');

        return new UploadedFile($path, 'slip.jpg', 'image/jpeg', null, true);
    }

    public function test_a_payment_proof_is_drawn_again_and_shown_as_a_picture_only(): void
    {
        $staff = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $id = $this->actingAs($staff)->postJson('/api/v1/payment-proofs', ['file' => $this->jpegWithComment()])->assertCreated()->json('data.id');
        $stored = Storage::disk('local')->allFiles('payment-proofs')[0];
        $bytes = Storage::disk('local')->get($stored);
        $this->assertStringNotContainsString('SECRET-GPS', $bytes);
        $this->assertStringNotContainsString('<script>', $bytes);
        $this->assertNotFalse(getimagesizefromstring($bytes));

        $this->get("/api/v1/payment-proofs/{$id}")->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
    }

    public function test_a_picture_over_40_megapixels_is_refused_without_opening_it(): void
    {
        $staff = User::factory()->withRole($this->hotel, 'receptionist')->create();
        // A tiny PNG file that claims 10,000 × 5,000 pixels (an "image bomb"): only its header is read.
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 10000, 5000)."\x08\x02\x00\x00\x00".pack('N', 0);
        $path = tempnam(sys_get_temp_dir(), 'bomb').'.png';
        file_put_contents($path, $png.str_repeat("\0", 64));
        $this->actingAs($staff)->postJson('/api/v1/payment-proofs', ['file' => new UploadedFile($path, 'bomb.png', 'image/png', null, true)])
            ->assertJsonValidationErrors('file');
    }

    public function test_only_check_in_staff_see_the_full_guest_id_and_photo(): void
    {
        $guest = app(TenantContext::class)->runAs($this->hotel, function () {
            app(HotelSettings::class)->put(['id_photo_enabled' => true]);

            return Guest::create(['first_name' => 'Meron', 'last_name' => 'Alemu', 'id_type' => 'passport', 'id_number' => 'ET12344086']);
        });
        $reception = User::factory()->withRole($this->hotel, 'receptionist')->create();
        $this->actingAs($reception)->postJson("/api/v1/guests/{$guest->id}/id-photo", ['file' => $this->jpegWithComment()])->assertCreated();
        $this->getJson("/api/v1/guests/{$guest->id}")->assertJsonPath('data.id_number', 'ET12344086')->assertJsonPath('id_full', true)->assertJsonPath('id_photo.mime', 'image/jpeg');
        $this->get("/api/v1/guests/{$guest->id}/id-photo")->assertOk();

        $accountant = User::factory()->withRole($this->hotel, 'accountant')->create();
        $this->actingAs($accountant)->getJson("/api/v1/guests/{$guest->id}")->assertOk()
            ->assertJsonPath('data.id_number', Guest::maskId('ET12344086'))->assertJsonPath('id_full', false)->assertJsonPath('id_photo', null);
        $this->get("/api/v1/guests/{$guest->id}/id-photo")->assertForbidden();
    }
}
