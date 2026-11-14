<?php

namespace Tests\Feature\Tenancy;

use App\Models\Hotel;
use App\Models\User;
use App\Services\DemoHotel;
use Database\Seeders\PlatformSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * Every API route × every role (SECURITY_REPORT M8). For each route the protection is known:
 * - public: answers without a sign-in (a short list);
 * - permission:… / platform:…: every default hotel role (and a Super Admin team role) without it gets 403, never 2xx/5xx;
 * - checked inside the controller: listed below, each one also has its own tests;
 * - nobody signed in: 401 on every non-public route.
 * A NEW route that is in none of these lists fails this test – decide its protection and add it here.
 * (Another hotel's data on every route: IsolationSweepTest.)
 */
class AccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** Answer without a sign-in. */
    private const PUBLIC = [
        'GET api/v1/health', 'GET api/v1/branding', 'GET api/v1/branding/manifest', 'POST api/v1/auth/login', 'POST api/v1/demo/login',
        'POST api/v1/auth/two-factor', 'POST api/v1/auth/forgot-password', 'POST api/v1/auth/reset-password', 'POST api/v1/auth/two-factor/resend',
        'POST api/v1/auth/confirm-email',
    ];

    /** Signed in, and the controller decides (own data, or a permission checked inside – each has its own test). */
    private const INSIDE = [
        'POST api/v1/auth/logout', 'POST api/v1/auth/lock', 'POST api/v1/auth/unlock', 'POST api/v1/auth/pin-switch',
        'GET api/v1/me', 'PUT api/v1/me', 'PUT api/v1/me/email', 'PUT api/v1/me/password',
        'GET api/v1/me/security', 'POST api/v1/me/security/devices/sign-out-others', 'POST api/v1/me/security/devices/{id}/sign-out', 'PUT api/v1/me/security/pin',
        'POST api/v1/me/security/recovery-codes', 'POST api/v1/me/security/two-factor', 'POST api/v1/me/security/two-factor/confirm', 'POST api/v1/me/security/two-factor/disable',
        'GET api/v1/telegram', 'POST api/v1/telegram/link', 'DELETE api/v1/telegram/link', 'POST api/v1/telegram/test',
        'POST api/v1/impersonation/stop', 'GET api/v1/setup', 'POST api/v1/setup/finish', 'PUT api/v1/setup/steps/{step}',
        'GET api/v1/search', 'GET api/v1/smart/{place}', 'GET api/v1/whats-new', 'GET api/v1/notifications', 'POST api/v1/notifications/read',
        'GET api/v1/support', 'POST api/v1/support', 'GET api/v1/support/{id}', 'POST api/v1/support/{id}/messages',
        'GET api/v1/branches', 'GET api/v1/alert-events', 'POST api/v1/alert-events/{id}/ack',
        'GET api/v1/saved-views', 'POST api/v1/saved-views', 'PUT api/v1/saved-views/{id}', 'DELETE api/v1/saved-views/{id}',
        'POST api/v1/attendance/pin/{user}', 'POST api/v1/payment-proofs', 'GET api/v1/payment-proofs/{id}', 'PUT api/v1/payments/{payment}/proof',
        'GET api/v1/reports/drilldown', 'GET api/v1/reports/{key}', 'POST api/v1/rooms/{id}/status',
    ];

    /** @return list<array{route: Route, name: string, perms: list<string>, platform: list<string>}> */
    private function routes(): array
    {
        return collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => str_starts_with($r->uri(), 'api/'))
            ->flatMap(fn (Route $r) => collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->map(fn ($m) => [$r, $m]))
            ->map(function (array $x) {
                [$r, $m] = $x;
                $mw = $r->gatherMiddleware();
                $pick = fn (string $prefix) => collect($mw)->filter(fn ($w) => is_string($w) && str_starts_with($w, $prefix))->flatMap(fn ($w) => explode(',', substr($w, strlen($prefix))))->values()->all();

                return ['route' => $r, 'method' => $m, 'name' => "{$m} {$r->uri()}", 'perms' => $pick('permission:'), 'platform' => $pick('platform:'), 'auth' => in_array('auth:sanctum', $mw, true)];
            })->values()->all();
    }

    private function url(Route $r): string
    {
        return '/'.preg_replace('/\{[^}]+\}/', '1', $r->uri());
    }

    public function test_every_route_has_a_known_protection(): void
    {
        $unknown = collect($this->routes())->filter(fn ($x) => ! in_array($x['name'], self::PUBLIC, true) && ! in_array($x['name'], self::INSIDE, true) && ! $x['perms'] && ! $x['platform'])
            ->pluck('name')->values()->all();
        $this->assertSame([], $unknown, 'New routes without permission:/platform: middleware – decide their protection and list them here.');

        $publicButSigned = collect($this->routes())->filter(fn ($x) => in_array($x['name'], self::PUBLIC, true) && $x['auth'])->pluck('name')->all();
        $this->assertSame([], $publicButSigned);
        $insideButPublic = collect($this->routes())->filter(fn ($x) => in_array($x['name'], self::INSIDE, true) && ! $x['auth'])->pluck('name')->all();
        $this->assertSame([], $insideButPublic, 'Routes listed as "checked inside" must still need a sign-in.');
    }

    public function test_nobody_signed_in_gets_401_on_every_non_public_route(): void
    {
        $wrong = [];
        foreach ($this->routes() as $x) {
            if (in_array($x['name'], self::PUBLIC, true)) {
                continue;
            }
            $status = $this->json($x['method'], $this->url($x['route']))->status();
            if ($status !== 401) {
                $wrong[] = "{$x['name']} → {$status}";
            }
        }
        $this->assertSame([], $wrong);
    }

    public function test_every_role_without_the_permission_is_refused(): void
    {
        RateLimiter::for('api', fn () => Limit::none()); // this test makes many calls on purpose
        $this->seed(PlatformSeeder::class);
        $hotel = app(DemoHotel::class)->reset(); // every module on, one person per role
        $people = collect(array_keys(DemoHotel::PEOPLE))->reject(fn ($k) => $k === 'hotel_admin')
            ->mapWithKeys(fn ($k) => [$k => User::where('email', DemoHotel::email($k))->firstOrFail()]);
        $team = User::factory()->superAdmin()->create(['platform_role' => 'support']); // the narrowest team role

        $wrong = [];
        foreach ($this->routes() as $x) {
            $url = $this->url($x['route']);
            if ($x['perms']) {
                foreach ($people as $key => $user) {
                    if (collect($x['perms'])->contains(fn ($p) => $user->hasPermission($p))) {
                        continue;
                    }
                    $status = $this->actingAs($user)->json($x['method'], $url)->status();
                    if (! in_array($status, [403, 404], true)) { // 404: a module that is off, or no such record – never data
                        $wrong[] = "{$key}: {$x['name']} → {$status}";
                    }
                }
            }
            if ($x['platform']) {
                // Hotel staff never reach the HotelMS area.
                $ok = str_contains($x['route']->uri(), '{') ? [403, 404] : [403]; // {finding}: no such record is 404 before the check
                $status = $this->actingAs($people['branch_manager'])->json($x['method'], $url)->status();
                if (! in_array($status, $ok, true)) {
                    $wrong[] = "branch_manager: {$x['name']} → {$status}";
                }
                $allowed = collect($x['platform'])->contains(fn ($p) => $team->hasPlatformPermission($p));
                if (! $allowed) {
                    $status = $this->actingAs($team)->json($x['method'], $url)->status();
                    if (! in_array($status, $ok, true)) {
                        $wrong[] = "support team: {$x['name']} → {$status}";
                    }
                }
            }
        }
        $this->assertNotEmpty($people);
        $this->assertSame([], $wrong);
        $this->assertInstanceOf(Hotel::class, $hotel);
    }
}
