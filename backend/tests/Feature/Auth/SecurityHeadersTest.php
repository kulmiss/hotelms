<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

/** Browser safety headers on API answers, and cross-origin rules (SECURITY_REPORT M1, L2, L3). */
class SecurityHeadersTest extends TestCase
{
    public function test_api_answers_can_never_run_as_a_page(): void
    {
        $this->getJson('/api/v1/health')->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'")
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeaderMissing('Strict-Transport-Security'); // only over HTTPS

        $this->getJson('https://localhost/api/v1/health')->assertHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
    }

    public function test_only_the_apps_own_address_may_call_the_api_from_another_origin(): void
    {
        config(['cors.allowed_origins' => ['https://app.example.et']]);

        $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], ['HTTP_ORIGIN' => 'https://app.example.et', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST'])
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.et')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        $evil = $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST']);
        $this->assertNotSame('https://evil.example', $evil->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $evil->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_the_server_files_send_the_app_page_policy(): void
    {
        $htaccess = (string) file_get_contents(public_path('.htaccess'));
        $this->assertStringContainsString('Content-Security-Policy "default-src \'self\'; script-src \'self\' \'sha256-', $htaccess);
        $this->assertStringContainsString("frame-ancestors 'none'", $htaccess);
        $this->assertStringContainsString("object-src 'none'", $htaccess);
        $this->assertStringContainsString('Strict-Transport-Security "max-age=63072000; includeSubDomains"', $htaccess);
        $this->assertStringContainsString('Permissions-Policy', $htaccess);
    }
}
