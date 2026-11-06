<?php

namespace Tests\Feature\Tenancy;

use App\Models\Branch;
use App\Models\Hotel;
use App\Models\Plan;
use App\Models\User;
use App\Services\DemoHotel;
use App\Services\HotelDefaults;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The most important rule (CLAUDE.md): one hotel never sees or changes another hotel's data,
 * and a person limited to some branches never sees the others.
 *
 * Every API route is tried as another hotel's Hotel Admin, with the busy demo hotel's real ids put
 * in every {id}. Nothing may come back that belongs to the demo hotel, and nothing may be changed.
 */
class IsolationSweepTest extends TestCase
{
    use RefreshDatabase;

    private Hotel $victim;

    private User $attacker;

    /** @var array<string, string> table => an id of the victim hotel */
    private array $ids = [];

    /** @var list<string> texts that only exist in the victim hotel */
    private array $markers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlatformSeeder::class);
        $this->victim = app(DemoHotel::class)->reset();
        $h = $this->victim->id;
        $id = fn (string $table, array $where = []) => (string) (DB::table($table)->where('hotel_id', $h)->where($where)->orderByDesc('id')->value('id') ?? 0);
        $this->ids = [
            'reservations' => $id('reservations', ['status' => 'checked_in']), 'guests' => $id('guests'), 'invoices' => $id('invoices'),
            'payments' => $id('payments'), 'roles' => $id('roles'), 'users' => $id('users'), 'rooms' => $id('rooms'), 'branches' => $id('branches'),
            'room_types' => $id('room_types'), 'taxes' => $id('taxes'), 'payment_methods' => $id('payment_methods'), 'charge_types' => $id('charge_types'),
            'guest_types' => $id('guest_types'), 'rate_rules' => $id('rate_rules'), 'cancellation_policies' => $id('cancellation_policies'),
            'hotel_currencies' => $id('hotel_currencies'), 'housekeeping_tasks' => $id('housekeeping_tasks'), 'cashier_sessions' => $id('cashier_sessions'),
            'shifts' => $id('shifts'), 'shift_assignments' => $id('shift_assignments'), 'report_schedules' => $id('report_schedules'), 'alert_rules' => $id('alert_rules'),
        ];
        // Private files too.
        $payment = DB::table('payments')->where('id', $this->ids['payments'])->first();
        $this->ids['payment_proofs'] = (string) DB::table('payment_proofs')->insertGetId(['hotel_id' => $h, 'payment_id' => $payment->id, 'path' => 'payment-proofs/x.jpg', 'mime' => 'image/jpeg', 'size' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $this->markers = array_values(array_filter(array_merge(
            [$this->victim->name, 'hotelms.demo'],
            DB::table('reservations')->where('hotel_id', $h)->latest('id')->limit(40)->pluck('code')->all(),
            DB::table('invoices')->where('hotel_id', $h)->latest('id')->limit(40)->pluck('number')->all(),
            DB::table('guests')->where('hotel_id', $h)->latest('id')->limit(40)->pluck('phone')->all(),
        )));

        // The other hotel: its own Hotel Admin (every permission), a branch, a room.
        $plan = Plan::where('code', 'pro')->first() ?? Plan::factory()->create(['max_rooms' => null]);
        $other = Hotel::factory()->create(['name' => 'Attacker Lodge', 'status' => 'active', 'plan_id' => $plan->id, 'setup_completed_at' => now()]);
        Branch::factory()->for($other)->create(['name' => 'Main', 'code' => 'ATK']);
        app(HotelDefaults::class)->apply($other);
        $this->attacker = User::factory()->withRole($other, 'hotel_admin')->create(['email' => 'boss@attacker.test']);
    }

    /** @return list<Route> */
    private function routes(): array
    {
        return collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => str_starts_with($r->uri(), 'api/v1/'))
            ->reject(fn (Route $r) => preg_match('#^api/v1/(admin|auth|demo/|branding|health|password|telegram/webhook|impersonation)#', $r->uri()))
            ->values()->all();
    }

    /** The victim's id for each {parameter}, from the path segment before it. */
    private function fill(string $uri): string
    {
        $tables = [
            'reservations' => 'reservations', 'guests' => 'guests', 'invoices' => 'invoices', 'payments' => 'payments', 'payment-proofs' => 'payment_proofs',
            'roles' => 'roles', 'staff' => 'users', 'pin' => 'users', 'audit' => 'users', 'rooms' => 'rooms', 'branches' => 'branches', 'room-types' => 'room_types',
            'taxes' => 'taxes', 'payment-methods' => 'payment_methods', 'charge-types' => 'charge_types', 'guest-types' => 'guest_types', 'rate-rules' => 'rate_rules',
            'cancellation-policies' => 'cancellation_policies', 'currencies' => 'hotel_currencies', 'tasks' => 'housekeeping_tasks', 'sessions' => 'cashier_sessions',
            'shifts' => 'shifts', 'roster' => 'shift_assignments', 'report-schedules' => 'report_schedules', 'alert-rules' => 'alert_rules',
        ];

        return preg_replace_callback('#([a-z\-]+)/\{([a-zA-Z]+)\}#', function ($m) use ($tables) {
            $table = $tables[$m[1]] ?? null;

            return $m[1].'/'.($table ? ($this->ids[$table] ?? '999999') : '999999');
        }, $uri);
    }

    private function leaks(TestResponse $res): ?string
    {
        $body = (string) $res->baseResponse->getContent();
        foreach ($this->markers as $m) {
            if ($m !== '' && str_contains($body, (string) $m)) {
                return $m;
            }
        }

        return null;
    }

    public function test_another_hotel_can_never_read_or_change_this_hotels_data(): void
    {
        $before = DB::table('reservations')->where('hotel_id', $this->victim->id)->orderBy('id')->get(['id', 'status', 'updated_at'])->toJson();
        $problems = [];
        $tried = 0;
        $query = '?branch_id='.$this->ids['branches'].'&from='.now()->subDays(30)->toDateString().'&to='.now()->toDateString().'&q=a&room_id='.$this->ids['rooms'].'&id='.$this->ids['reservations'];

        foreach ($this->routes() as $route) {
            $uri = '/'.$this->fill($route->uri());
            if (str_contains($uri, '{')) {
                $uri = preg_replace('#\{[a-zA-Z]+\}#', 'dashboard', $uri); // {place}, {key}, {action}…
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->actingAs($this->attacker);
                $res = $method === 'GET' ? $this->getJson($uri.$query) : $this->json($method, $uri, ['branch_id' => (int) $this->ids['branches'], 'reservation_id' => (int) $this->ids['reservations'], 'guest_id' => (int) $this->ids['guests'], 'room_id' => (int) $this->ids['rooms']]);
                $tried++;
                if ($leak = $this->leaks($res)) {
                    $problems[] = "{$method} {$uri} shows \"{$leak}\" ({$res->getStatusCode()})";
                }
                app(TenantContext::class)->set(null);
            }
        }

        $this->assertGreaterThan(150, $tried);
        $this->assertSame([], $problems, implode("\n", $problems));
        // Nothing of the hotel was changed by the other hotel.
        $this->assertSame($before, DB::table('reservations')->where('hotel_id', $this->victim->id)->orderBy('id')->get(['id', 'status', 'updated_at'])->toJson());
        $this->assertSame(0, DB::table('reservations')->where('hotel_id', $this->victim->id)->where('created_by', $this->attacker->id)->count());
        $this->assertSame(0, DB::table('activity_logs')->where('hotel_id', $this->victim->id)->where('user_id', $this->attacker->id)->count());
    }

    public function test_a_person_limited_to_one_branch_never_sees_the_other_branch(): void
    {
        $manager = User::where('email', DemoHotel::email('branch_manager'))->firstOrFail(); // Bole only
        $piassa = DB::table('branches')->where('hotel_id', $this->victim->id)->where('name', 'Piassa branch')->value('id');
        $res = DB::table('reservations')->where('branch_id', $piassa)->where('status', 'checked_in')->orderByDesc('id')->first();
        $inv = DB::table('invoices')->where('branch_id', $piassa)->orderByDesc('id')->value('id');
        $pay = DB::table('payments')->where('branch_id', $piassa)->orderByDesc('id')->value('id');
        $proof = DB::table('payment_proofs')->insertGetId(['hotel_id' => $this->victim->id, 'payment_id' => $pay, 'path' => 'payment-proofs/y.jpg', 'mime' => 'image/jpeg', 'size' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $codes = DB::table('reservations')->where('branch_id', $piassa)->latest('id')->limit(30)->pluck('code')->all();

        $this->actingAs($manager);
        foreach (["/api/v1/reservations/{$res->id}", "/api/v1/reservations/{$res->id}/check-out", "/api/v1/reservations/{$res->id}/registration-card",
            "/api/v1/invoices/{$inv}", "/api/v1/payment-proofs/{$proof}", "/api/v1/smart/reservation?id={$res->id}"] as $url) {
            $this->assertContains($this->getJson($url)->getStatusCode(), [403, 404], $url);
        }
        $method = DB::table('payment_methods')->where('hotel_id', $this->victim->id)->value('id');
        $this->postJson("/api/v1/reservations/{$res->id}/payments", ['type' => 'payment', 'payment_method_id' => $method, 'amount' => '1'])->assertNotFound();

        // Lists asked for the other branch fall back to the person's own branches.
        foreach (['/api/v1/reservations', '/api/v1/invoices', '/api/v1/payments', '/api/v1/front-desk/today', '/api/v1/front-desk/board',
            '/api/v1/reservations/calendar?from='.now()->toDateString(), '/api/v1/reports/payments?from='.now()->subDays(30)->toDateString().'&to='.now()->toDateString()] as $url) {
            $body = (string) $this->getJson($url.(str_contains($url, '?') ? '&' : '?')."branch_id={$piassa}")->baseResponse->getContent();
            foreach ($codes as $code) {
                $this->assertStringNotContainsString($code, $body, "{$url} shows {$code} of the other branch");
            }
        }
    }

    public function test_each_person_sees_only_their_own_notifications_tasks_and_shifts(): void
    {
        $reception = User::where('email', DemoHotel::email('receptionist'))->firstOrFail();
        $admin = User::where('email', DemoHotel::email('hotel_admin'))->firstOrFail();
        DB::table('app_notifications')->insert(['hotel_id' => $this->victim->id, 'user_id' => $admin->id, 'source' => 'test', 'title' => 'Only for Selam', 'created_at' => now()]);
        DB::table('saved_views')->insert(['hotel_id' => $this->victim->id, 'user_id' => $admin->id, 'report_key' => 'performance', 'name' => 'Selam private view', 'filters' => '{}', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($reception);
        $this->assertStringNotContainsString('Only for Selam', (string) $this->getJson('/api/v1/notifications')->baseResponse->getContent());
        $this->assertStringNotContainsString('Selam private view', (string) $this->getJson('/api/v1/saved-views')->baseResponse->getContent());
        $session = DB::table('cashier_sessions')->where('hotel_id', $this->victim->id)->where('user_id', '!=', $reception->id)->value('id');
        if ($session) {
            $this->getJson("/api/v1/cashier/sessions/{$session}")->assertNotFound(); // reception sees only their own cashier shifts
        }
    }
}
