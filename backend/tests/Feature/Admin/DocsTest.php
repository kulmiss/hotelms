<?php

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DocsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/docs');
        File::ensureDirectoryExists($this->dir);
        File::put($this->dir.'/README.md', "# Start here\n\nRead [the money rules](architecture.md#money).\n\n<script>alert(1)</script>\n");
        File::put($this->dir.'/architecture.md', "# Architecture\n\n| A | B |\n|---|---|\n| money | strings |\n");
        config(['hotelms.docs_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_the_owner_and_tech_read_the_developer_docs_in_the_app(): void
    {
        foreach ([User::factory()->superAdmin()->create(), User::factory()->superAdmin()->create(['platform_role' => 'tech'])] as $who) {
            $this->actingAs($who)->getJson('/api/v1/admin/docs')->assertOk()
                ->assertJsonPath('data.0.name', 'readme')->assertJsonPath('data.0.title', 'Start here')->assertJsonPath('data.1.title', 'Architecture');
        }

        $html = $this->getJson('/api/v1/admin/docs/readme')->assertOk()->json('data.html');
        $this->assertStringContainsString('href="/admin/docs/architecture#money"', $html); // opens in the app
        $this->assertStringNotContainsString('<script', $html); // raw HTML is removed
        $this->assertStringContainsString('<table>', $this->getJson('/api/v1/admin/docs/architecture')->json('data.html'));

        $this->getJson('/api/v1/admin/docs?q=strings')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'architecture');
        $this->getJson('/api/v1/admin/docs/nothing')->assertNotFound();
        $this->get('/api/v1/admin/docs/..%2F.env')->assertNotFound(); // only names from the list
    }

    public function test_nobody_else_can_read_them(): void
    {
        $support = User::factory()->superAdmin()->create(['platform_role' => 'support']);
        $this->actingAs($support)->getJson('/api/v1/admin/docs')->assertForbidden();

        $hotel = Hotel::factory()->create();
        Branch::factory()->for($hotel)->create();
        $admin = User::factory()->withRole($hotel, 'hotel_admin')->create();
        $this->actingAs($admin)->getJson('/api/v1/admin/docs/readme')->assertForbidden();
    }
}
