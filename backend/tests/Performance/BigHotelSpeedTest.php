<?php

namespace Tests\Performance;

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
 * Speed with a big hotel: 160 rooms and a busy year of bookings. Every screen's data (each GET)
 * is timed and its database questions counted, for the Hotel Admin (sees everything) with a
 * month and with a whole year. Run: php artisan test tests/Performance
 * Prints a table of the slowest answers; fails when one is too slow or asks the database too often.
 */
class BigHotelSpeedTest extends TestCase
{
    use RefreshDatabase;

    private const ROOMS = 160;

    /** Limits (generous for the shared CI machine; a real server is faster). */
    private const MAX_MS = 1500;

    private const MAX_QUERIES = 80;

    private Hotel $hotel;

    /** @var array<string, string> */
    private array $ids = [];

    public function test_every_screen_is_quick_with_a_big_hotel(): void
    {
        $this->seed(PlatformSeeder::class);
        $t = microtime(true);
        $this->hotel = app(DemoHotel::class)->reset(self::ROOMS);
        $h = $this->hotel->id;
        $count = fn (string $table) => DB::table($table)->where('hotel_id', $h)->count();
        $built = sprintf('Built in %.1fs: %d rooms, %d bookings, %d guests, %d invoices, %d payments',
            microtime(true) - $t, $count('rooms'), $count('reservations'), $count('guests'), $count('invoices'), $count('payments'));

        $any = fn (string $table, array $where = []) => (string) (DB::table($table)->where('hotel_id', $h)->where($where)->orderByDesc('id')->value('id') ?? 0);
        $this->ids = [
            'reservations' => $any('reservations', ['status' => 'checked_in']), 'guests' => $any('guests'), 'invoices' => $any('invoices'),
            'roles' => (string) DB::table('roles')->where('hotel_id', $h)->value('id'), 'staff' => (string) User::where('hotel_id', $h)->value('id'),
            'branch' => (string) DB::table('branches')->where('hotel_id', $h)->orderBy('id')->value('id'),
        ];

        $rows = [];
        $this->actingAs(User::where('email', DemoHotel::email('hotel_admin'))->firstOrFail());
        foreach ([30, 365] as $days) {
            foreach ($this->hotelRoutes() as $route) {
                foreach ($this->urls($route, $days) as $url) {
                    $rows[] = $this->measure($url, $days);
                }
            }
        }
        $this->actingAs(User::factory()->superAdmin()->create());
        foreach (['/admin/dashboard', '/admin/hotels', "/admin/hotels/{$h}", "/admin/hotels/{$h}/overview", '/admin/reports/platform',
            '/admin/reports/hotel-health', '/admin/system', '/admin/activity', '/admin/watchdog', '/smart/admin', '/search?q=sheger'] as $url) {
            $rows[] = $this->measure("/api/v1{$url}", 0);
        }

        usort($rows, fn ($a, $b) => $b['ms'] <=> $a['ms']);
        $lines = array_map(fn ($r) => sprintf('%6.0f ms %4d q  %3d  %s', $r['ms'], $r['q'], $r['status'], $r['url']), $rows);
        $report = $built."\nSlowest:\n".implode("\n", array_slice($lines, 0, 40))."\nMost database questions:\n"
            .implode("\n", array_map(fn ($r) => sprintf('%4d q %6.0f ms  %s', $r['q'], $r['ms'], $r['url']), array_slice(collect($rows)->sortByDesc('q')->values()->all(), 0, 15)));
        fwrite(STDERR, "\n".$report."\n");
        @file_put_contents(storage_path('logs/speed-report.txt'), $report);

        $errors = array_filter($rows, fn ($r) => $r['status'] >= 500);
        $this->assertSame([], array_values(array_map(fn ($r) => $r['url'], $errors)));
        $slow = array_filter($rows, fn ($r) => $r['ms'] > self::MAX_MS || $r['q'] > self::MAX_QUERIES);
        $this->assertSame([], array_values(array_map(fn ($r) => sprintf('%.0f ms %d q %s', $r['ms'], $r['q'], $r['url']), $slow)), 'Too slow');
    }

    /** @return array{url: string, ms: float, q: int, status: int} */
    private function measure(string $url, int $days): array
    {
        $this->getJson($url); // warm up (first use of a class or cache)
        app(TenantContext::class)->set(null);
        $q = 0;
        DB::listen(function () use (&$q) {
            $q++;
        });
        $t = microtime(true);
        $res = $this->getJson($url);
        $ms = (microtime(true) - $t) * 1000;
        DB::getEventDispatcher()?->forget('Illuminate\Database\Events\QueryExecuted');
        app(TenantContext::class)->set(null);

        return ['url' => ($days ? "[{$days}d] " : '').preg_replace('/\?.*$/', '', $url), 'ms' => $ms, 'q' => $q, 'status' => $res->getStatusCode()];
    }

    /** @return list<Route> */
    private function hotelRoutes(): array
    {
        return collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => in_array('GET', $r->methods(), true) && str_starts_with($r->uri(), 'api/v1/'))
            ->reject(fn (Route $r) => preg_match('#^api/v1/(admin|auth|demo|branding|health|export|import/template|telegram|password|settings/export)#', $r->uri()))
            ->values()->all();
    }

    /** @return list<string> */
    private function urls(Route $route, int $days): array
    {
        $uri = '/'.$route->uri();
        $from = now()->subDays($days)->toDateString();
        $to = now()->toDateString();
        $q = "?from={$from}&to={$to}&date={$to}&month=".now()->format('Y-m')."&metric=room_nights&q=ab"
            ."&check_in={$to}&check_out=".now()->addDays(3)->toDateString().'&stay_type=nightly';
        // A month: only screens that take dates are measured again with a year.
        if ($days === 365 && ! preg_match('#reports|dashboard|calendar|payments|invoices|attendance|activity|cashier/sessions|smart#', $uri)) {
            return [];
        }
        if (str_contains($uri, '{key}')) {
            return array_map(fn ($k) => str_replace('{key}', $k, $uri).$q, array_keys(ReportController::REPORTS));
        }
        if (str_contains($uri, '{place}')) {
            return array_map(fn ($p) => str_replace('{place}', $p, $uri).$q."&id={$this->ids['reservations']}&phone=0911223344&branch_id={$this->ids['branch']}",
                ['dashboard', 'reservation', 'check_in', 'guest_check', 'cashier', 'housekeeping', 'shifts', 'report', 'rooms_settings']);
        }
        foreach (['#^/api/v1/reservations/\{id\}#' => 'reservations', '#^/api/v1/guests/\{id\}#' => 'guests', '#^/api/v1/invoices/\{id\}#' => 'invoices', '#\{role\}#' => 'roles', '#\{user\}#' => 'staff'] as $pattern => $key) {
            if (preg_match($pattern, $uri)) {
                $uri = preg_replace('#\{[a-z]+\}#', $this->ids[$key], $uri);
            }
        }

        if (preg_match('#housekeeping|shifts|attendance|walk-in|cashier|front-desk#', $uri)) {
            $q .= "&branch_id={$this->ids['branch']}";
        }

        return str_contains($uri, '{') ? [] : [$uri.$q];
    }
}
