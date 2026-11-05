<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_reports_ok(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('app', 'HotelMS')
            ->assertJsonPath('checks.database', 'ok');
    }

    public function test_root_points_to_the_api(): void
    {
        $this->getJson('/')
            ->assertOk()
            ->assertJsonStructure(['app', 'api']);
    }

    public function test_unknown_api_route_returns_json_404(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }
}
