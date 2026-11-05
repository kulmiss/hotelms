<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_branding_is_public_and_follows_the_settings(): void
    {
        $this->getJson('/api/v1/branding')->assertOk()
            ->assertJsonPath('data.platform_name', 'HotelMS')
            ->assertJsonPath('data.brand_color', '#0f766e')
            ->assertJsonMissingPath('data.default_trial_days');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson('/api/v1/admin/settings', ['platform_name' => 'Sheger Suite', 'brand_color' => '#7c3aed', 'login_message' => 'Welcome back'])
            ->assertOk();

        $this->getJson('/api/v1/branding')
            ->assertJsonPath('data.platform_name', 'Sheger Suite')
            ->assertJsonPath('data.brand_color', '#7c3aed')
            ->assertJsonPath('data.login_message', 'Welcome back');
    }

    public function test_colour_must_be_a_hex_value(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->putJson('/api/v1/admin/settings', ['brand_color' => 'red'])
            ->assertUnprocessable()->assertJsonValidationErrors('brand_color');
    }

    public function test_logo_upload_and_removal(): void
    {
        Storage::fake('public');
        $owner = User::factory()->superAdmin()->create();

        $url = $this->actingAs($owner)
            ->post('/api/v1/admin/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png', 200, 60)], ['Accept' => 'application/json'])
            ->assertOk()->json('data.brand_logo_url');

        $this->assertStringStartsWith('/storage/branding/', $url);
        Storage::disk('public')->assertExists(substr($url, strlen('/storage/')));

        $this->post('/api/v1/admin/settings/logo', [], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.brand_logo_url', null);
    }

    public function test_only_billing_managers_change_platform_settings(): void
    {
        $this->actingAs(User::factory()->platformRole('support')->create())
            ->putJson('/api/v1/admin/settings', ['platform_name' => 'X'])
            ->assertForbidden();
    }
}
