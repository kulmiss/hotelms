<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DemoHotel;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * Launch recheck: every API route that changes something is sent broken data (lists, very long text,
 * nested objects, negative numbers) by the busy demo hotel's own Hotel Admin. The answer may be
 * "not valid" or "not allowed", but never a server error (500).
 */
class FuzzSweepTest extends TestCase
{
    use RefreshDatabase;

    /** Field names used by the app's forms (the junk goes into all of them at once). */
    private const FIELDS = [
        'reason', 'name', 'notes', 'q', 'amount', 'pin', 'type', 'status', 'description', 'unit_price', 'quantity', 'discount_percent',
        'check_in', 'check_out', 'stay_type', 'branch_id', 'guest_id', 'room_id', 'rooms', 'payment', 'payment_method_id', 'currency',
        'reference', 'refund_reason', 'email', 'phone', 'first_name', 'last_name', 'company', 'tin', 'counted', 'opening', 'permissions',
        'role_id', 'branch_ids', 'password', 'current_password', 'code', 'date', 'from', 'to', 'hours', 'priority', 'assigned_to',
        'start_time', 'end_time', 'user_id', 'shift_id', 'value', 'adjustment', 'rule_type', 'room_type_id', 'enabled', 'options',
        'conditions', 'actions', 'filters', 'channels', 'recipients', 'threshold', 'ids', 'proof_id', 'charge_type_id', 'service_date',
        'is_blacklisted', 'waive_early_fee', 'waive_late_fee', 'approve_balance', 'open_stay', 'pay_later', 'quick_guest', 'measures',
        'group', 'report_key', 'chart_type', 'frequency', 'time', 'subject', 'body', 'message', 'title', 'key', 'level', 'target_id',
        'max_discount_percent', 'deposit_value', 'night_cutoff', 'clock_in', 'clock_out', 'session', 'period', 'month', 'note', 'rows',
        'number', 'floor', 'nightly_price', 'base_capacity', 'max_capacity', 'rate', 'is_inclusive', 'confirm_overpay', 'walk_in',
    ];

    public function test_broken_data_never_causes_a_server_error(): void
    {
        $this->seed(PlatformSeeder::class);
        $hotel = app(DemoHotel::class)->reset();
        $admin = User::where('hotel_id', $hotel->id)->whereHas('role', fn ($q) => $q->withoutGlobalScope('hotel')->where('key', 'hotel_admin'))->firstOrFail();
        $id = fn (string $table) => (string) (DB::table($table)->where('hotel_id', $hotel->id)->orderByDesc('id')->value('id') ?? 1);
        $ids = [
            'reservations' => $id('reservations'), 'guests' => $id('guests'), 'invoices' => $id('invoices'), 'payments' => $id('payments'),
            'roles' => $id('roles'), 'staff' => $id('users'), 'pin' => $id('users'), 'rooms' => $id('rooms'), 'branches' => $id('branches'),
            'tasks' => $id('housekeeping_tasks'), 'sessions' => $id('cashier_sessions'), 'shifts' => $id('shifts'), 'items' => '1', 'charges' => '1',
        ];
        $payloads = [
            'lists' => array_fill_keys(self::FIELDS, ['x', ['y']]),
            'long text' => array_fill_keys(self::FIELDS, str_repeat('x', 5000)),
            'objects' => array_fill_keys(self::FIELDS, ['a' => ['b' => ['c' => 1]]]),
            'negative' => array_fill_keys(self::FIELDS, -1),
        ];

        $routes = collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => str_starts_with($r->uri(), 'api/v1/') && array_diff($r->methods(), ['GET', 'HEAD']))
            ->reject(fn (Route $r) => preg_match('#^api/v1/(admin|demo/|auth/(logout|pin-switch)|impersonation|telegram/webhook)#', $r->uri()));

        $errors = [];
        $tried = 0;
        foreach ($routes as $route) {
            $uri = '/'.preg_replace_callback('#([a-z\-]+)/\{([a-zA-Z]+)\}#', fn ($m) => $m[1].'/'.($ids[$m[1]] ?? '1'), $route->uri());
            $uri = preg_replace('#\{[a-zA-Z]+\}#', '1', $uri);
            foreach (array_diff($route->methods(), ['GET', 'HEAD']) as $method) {
                foreach ($payloads as $kind => $body) {
                    $this->actingAs($admin);
                    $res = $this->json($method, $uri, $body);
                    $tried++;
                    if ($res->getStatusCode() >= 500) {
                        $errors[] = "{$method} {$uri} ({$kind}): ".mb_substr((string) ($res->json('message') ?? ''), 0, 160);
                    }
                    app(TenantContext::class)->set(null);
                }
            }
        }

        $this->assertGreaterThan(300, $tried);
        $this->assertSame([], $errors, implode("\n", $errors));
    }

    public function test_broken_data_to_super_admin_screens_never_causes_a_server_error(): void
    {
        $this->seed(PlatformSeeder::class);
        $hotel = app(DemoHotel::class)->reset();
        $owner = User::factory()->superAdmin()->create();
        $payloads = [
            array_fill_keys(self::FIELDS, ['x', ['y']]),
            array_fill_keys(self::FIELDS, str_repeat('x', 5000)),
            array_fill_keys(self::FIELDS, ['a' => ['b' => 1]]),
        ];
        $routes = collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => str_starts_with($r->uri(), 'api/v1/admin/') && array_diff($r->methods(), ['GET', 'HEAD']))
            ->reject(fn (Route $r) => str_contains($r->uri(), 'impersonate') || str_contains($r->uri(), 'demo/reset'));

        $errors = [];
        foreach ($routes as $route) {
            $uri = '/'.preg_replace_callback('#\{([a-zA-Z]+)\}#', fn ($m) => $m[1] === 'hotel' ? (string) $hotel->id : '1', $route->uri());
            foreach (array_diff($route->methods(), ['GET', 'HEAD']) as $method) {
                foreach ($payloads as $body) {
                    $res = $this->actingAs($owner)->json($method, $uri, $body);
                    if ($res->getStatusCode() >= 500) {
                        $errors[] = "{$method} {$uri}: ".mb_substr((string) ($res->json('message') ?? ''), 0, 160);
                    }
                }
            }
        }

        $this->assertSame([], $errors, implode("\n", $errors));
    }

    public function test_broken_filters_in_links_never_cause_a_server_error(): void
    {
        $this->seed(PlatformSeeder::class);
        $hotel = app(DemoHotel::class)->reset();
        $admin = User::where('hotel_id', $hotel->id)->whereHas('role', fn ($q) => $q->withoutGlobalScope('hotel')->where('key', 'hotel_admin'))->firstOrFail();
        $owner = User::factory()->superAdmin()->create();
        $queries = [
            '?from=abc&to=xyz&date=2026-13-45&month=13-2026&q=%25%27%22&page=-5&per_page=-1&sort=nope&dir=sideways&branch_id=abc&status=nope&format=pdf',
            '?from=1900-01-01&to=2999-12-31&days=9999&hours=-3&check_in=2026-02-30&check_out=yesterday&stay_type=x&metric=x&period=x&session=abc',
            '?q='.str_repeat('x', 3000).'&per_page=100000&page=999999',
        ];
        $routes = collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => str_starts_with($r->uri(), 'api/v1/') && in_array('GET', $r->methods(), true))
            ->reject(fn (Route $r) => preg_match('#^api/v1/(telegram/webhook|health)#', $r->uri()));

        $errors = [];
        foreach ($routes as $route) {
            $uri = '/'.preg_replace('#\{[a-zA-Z]+\}#', '1', $route->uri());
            $user = str_starts_with($route->uri(), 'api/v1/admin/') ? $owner : $admin;
            foreach ($queries as $query) {
                $res = $this->actingAs($user)->getJson($uri.$query);
                if ($res->getStatusCode() >= 500) {
                    $errors[] = "GET {$uri}{$query}: ".mb_substr((string) ($res->json('message') ?? ''), 0, 160);
                }
                app(TenantContext::class)->set(null);
            }
        }

        $this->assertSame([], $errors, implode("\n", $errors));
    }
}
