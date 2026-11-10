<?php

namespace Tests\Feature;

use App\Support\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/__test/client', fn (Request $r) => ['ip' => $r->ip(), 'secure' => $r->isSecure()]);
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    private function fromProxy(string $remote)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $remote])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->getJson('/__test/client');
    }

    public function test_by_default_nobody_can_fake_their_ip_or_https(): void
    {
        TrustedProxies::apply(config('hotelms.trusted_proxies'));   // empty in tests, as in .env.production.example

        $this->fromProxy('192.0.2.1')->assertJson(['ip' => '192.0.2.1', 'secure' => false]);
    }

    public function test_a_listed_proxy_passes_on_the_visitors_ip_and_https(): void
    {
        TrustedProxies::apply('10.0.0.0/8, 172.16.0.1');

        $this->fromProxy('10.0.0.5')->assertJson(['ip' => '203.0.113.9', 'secure' => true]);
    }

    public function test_anyone_else_sending_the_same_headers_is_still_seen_as_themselves(): void
    {
        TrustedProxies::apply('10.0.0.0/8, 172.16.0.1');

        $this->fromProxy('192.0.2.1')->assertJson(['ip' => '192.0.2.1', 'secure' => false]);
    }
}
