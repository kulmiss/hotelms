<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Reports\ReportController;
use App\Models\Hotel;
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
 * Every screen of the busy demo hotel, for every role: each page's data (every GET in the API)
 * must answer without a server error, and the pages a role may open must answer 200.
 */
class DemoEveryPageTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $hotel;

    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlatformSeeder::class);
        $this->hotel = app(DemoHotel::class)->reset();
        $h = $this->hotel->id;
        $any = fn (string $table, array $where = []) => (string) (DB::table($table)->where('hotel_id', $h)->where($where)->orderByDesc('id')->value('id') ?? 0);
        $this->ids = [
            'reservations' => $any('reservations', ['status' => 'checked_in']),
            'guests' => $any('guests'),
            'invoices' => $any('invoices'),
            'roles' => (string) DB::table('roles')->where('hotel_id', $h)->value('id'),
            'staff' => (string) User::where('hotel_id', $h)->value('id'),
            'branch' => (string) DB::table('branches')->where('hotel_id', $h)->orderBy('id')->value('id'),
        ];
    }

    /** The API GET routes for hotel screens (Super Admin, sign-in and file downloads are elsewhere). @return list<Route> */
    private function hotelRoutes(): array
    {
        return collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => in_array('GET', $r->methods(), true) && str_starts_with($r->uri(), 'api/v1/'))
            ->reject(fn (Route $r) => preg_match('#^api/v1/(admin|auth|demo|branding|health|export|import/template|telegram/|password)#', $r->uri()))
            ->values()->all();
    }

    /** @return list<string> */
    private function urls(Route $route): array
    {
        $uri = '/'.$route->uri();
        $from = now()->subDays(30)->toDateString();
        $to = now()->toDateString();
        $q = "?from={$from}&to={$to}&date={$to}&month=".now()->format('Y-m')."&branch_id={$this->ids['branch']}&metric=room_nights&q=ab"
            ."&check_in={$to}&check_out=".now()->addDay()->toDateString().'&stay_type=nightly';

        if (str_contains($uri, '{key}')) {
            return array_map(fn ($k) => str_replace('{key}', $k, $uri).$q, array_keys(ReportController::REPORTS));
        }
        if (str_contains($uri, '{place}')) {
            return array_map(fn ($p) => str_replace('{place}', $p, $uri).$q."&id={$this->ids['reservations']}&phone=0911223344",
                ['dashboard', 'reservation', 'check_in', 'guest_check', 'cashier', 'housekeeping', 'shifts', 'report', 'rooms_settings']);
        }
        $map = [
            '#^/api/v1/reservations/\{id\}#' => $this->ids['reservations'],
            '#^/api/v1/guests/\{id\}#' => $this->ids['guests'],
            '#^/api/v1/invoices/\{id\}#' => $this->ids['invoices'],
            '#\{role\}#' => $this->ids['roles'],
            '#\{user\}#' => $this->ids['staff'],
        ];
        foreach ($map as $pattern => $id) {
            if (preg_match($pattern, $uri)) {
                $uri = preg_replace('#\{[a-z]+\}#', $id, $uri);
            }
        }

        return str_contains($uri, '{') ? [] : [$uri.$q]; // pages for records the demo does not have (a support request, a shift)
    }

    public function test_every_page_for_every_role_answers_without_errors(): void
    {
        $failures = [];
        $ok = [];
        foreach (array_keys(DemoHotel::PEOPLE) as $role) {
            $user = User::where('email', DemoHotel::email($role))->firstOrFail();
            $this->actingAs($user);
            foreach ($this->hotelRoutes() as $route) {
                foreach ($this->urls($route) as $url) {
                    $res = $this->getJson($url);
                    $status = $res->getStatusCode(); // file downloads too
                    if ($status >= 500) {
                        $failures[] = "{$role} {$url} → {$status}: ".mb_substr((string) $res->baseResponse->getContent(), 0, 300);
                    }
                    if ($status === 200) {
                        $ok[$role][] = $url;
                    }
                    app(TenantContext::class)->set(null);
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
        // Each role really sees its own screens (not just "not allowed" everywhere).
        $this->assertGreaterThan(80, count($ok['hotel_admin']));
        foreach (['receptionist' => 25, 'branch_manager' => 40, 'accountant' => 20, 'housekeeping' => 8] as $role => $min) {
            $this->assertGreaterThan($min, count($ok[$role] ?? []), "{$role} sees too few pages");
        }
    }

    public function test_the_main_screens_load_for_the_people_who_use_them(): void
    {
        $expect = [
            'hotel_admin' => ['/dashboard', '/front-desk/board', '/reservations', '/reservations/calendar', '/guests', '/invoices', '/payments', '/reports/performance', '/reports/daily', '/settings/automations', '/approvals', '/staff', '/roles', '/activity-log', '/subscription'],
            'receptionist' => ['/dashboard', '/front-desk/board', '/front-desk/today', '/reservations', '/guests', '/invoices', '/cashier/current', '/shifts/mine'],
            'housekeeping' => ['/housekeeping/tasks', '/shifts/mine'],
            'accountant' => ['/invoices', '/payments', '/reports/payments', '/reports/outstanding', '/cashier/sessions'],
            'branch_manager' => ['/dashboard', '/housekeeping/board', '/shifts/roster', '/approvals', '/reports/performance'],
        ];
        $q = '?from='.now()->subDays(30)->toDateString().'&to='.now()->toDateString()."&branch_id={$this->ids['branch']}&date=".now()->toDateString();
        foreach ($expect as $role => $urls) {
            $this->actingAs(User::where('email', DemoHotel::email($role))->firstOrFail());
            foreach ($urls as $url) {
                $res = $this->getJson("/api/v1{$url}{$q}");
                $this->assertSame(200, $res->status(), "{$role} {$url}: ".mb_substr((string) $res->json('message'), 0, 200));
                app(TenantContext::class)->set(null);
            }
        }
    }

    public function test_super_admin_screens_with_the_busy_demo(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $id = $this->hotel->id;
        foreach (['/admin/dashboard', '/admin/hotels', "/admin/hotels/{$id}", "/admin/hotels/{$id}/overview", "/admin/hotels/{$id}/switches", "/admin/hotels/{$id}/invoices",
            '/admin/reports/platform', '/admin/reports/hotel-health', '/admin/support', '/admin/system', '/admin/activity', '/admin/team', '/admin/switches',
            '/admin/releases', '/admin/content', '/admin/plans', '/admin/settings', '/smart/admin', '/search?q=sheger', '/notifications', '/whats-new'] as $url) {
            $res = $this->getJson("/api/v1{$url}");
            $this->assertSame(200, $res->status(), "{$url}: ".mb_substr((string) $res->json('message'), 0, 200));
        }
    }
}
