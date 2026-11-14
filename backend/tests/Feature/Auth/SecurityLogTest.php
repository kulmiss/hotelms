<?php

namespace Tests\Feature\Auth;

use App\Models\Branch;
use App\Models\Hotel;
use App\Models\User;
use App\Services\Security\SecurityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The security event log (SECURITY_REPORT M9): what is written, who may read it, how long it is kept. */
class SecurityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_ins_failures_and_changes_are_logged_and_each_hotel_sees_only_its_own(): void
    {
        Mail::fake();
        $hotel = Hotel::factory()->create();
        Branch::factory()->for($hotel)->create();
        $other = Hotel::factory()->create();
        Branch::factory()->for($other)->create();
        $admin = User::factory()->withRole($hotel, 'hotel_admin')->create();
        $staff = User::factory()->withRole($hotel, 'receptionist')->create(['email' => 'hana@sheger.test']);
        $stranger = User::factory()->withRole($other, 'receptionist')->create(['email' => 'abel@other.test']);

        $this->postJson('/api/v1/auth/login', ['email' => 'hana@sheger.test', 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@sheger.test', 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => 'abel@other.test', 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', ['email' => 'hana@sheger.test', 'password' => 'password'])->assertOk();
        $this->putJson('/api/v1/me/password', ['current_password' => 'password', 'password' => 'Blue-Nile-Kettle-42', 'password_confirmation' => 'Blue-Nile-Kettle-42'])->assertOk();
        $this->getJson('/api/v1/staff')->assertForbidden(); // a receptionist may not: "denied"

        $kinds = DB::table('security_events')->where('user_id', $staff->id)->pluck('kind')->all();
        foreach (['login_failed', 'login_ok', 'password_changed', 'denied'] as $k) {
            $this->assertContains($k, $kinds);
        }
        $this->assertDatabaseHas('security_events', ['kind' => 'login_failed', 'user_id' => null, 'email' => 'nobody@sheger.test']);

        // The Hotel Admin sees this hotel's rows only (not the other hotel, not unknown emails).
        $this->getJson('/api/v1/security-events')->assertForbidden(); // receptionist
        $this->actingAs($admin);
        $rows = $this->getJson('/api/v1/security-events')->assertOk()->json('data');
        $this->assertNotEmpty($rows);
        $this->assertEmpty(array_filter($rows, fn ($r) => in_array($r['email'], ['abel@other.test', 'nobody@sheger.test'], true)));
        $this->assertSame('Wrong password', collect($rows)->firstWhere('kind', 'login_failed')['label']);
        $this->getJson('/api/v1/security-events?kind=password_changed')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/security-events')->assertForbidden();

        // The HotelMS team (watchdog.view) sees everything.
        $this->actingAs(User::factory()->superAdmin()->create());
        $all = collect($this->getJson('/api/v1/admin/security-events')->assertOk()->json('data'));
        $this->assertTrue($all->contains('email', 'nobody@sheger.test'));
        $this->assertTrue($all->contains('email', 'abel@other.test'));
        $this->assertSame([$stranger->id], DB::table('security_events')->where('hotel_id', $other->id)->pluck('user_id')->unique()->values()->all());
    }

    public function test_rows_are_kept_one_year(): void
    {
        DB::table('security_events')->insert([['kind' => 'login_ok', 'created_at' => now()->subDays(366)], ['kind' => 'login_ok', 'created_at' => now()->subDays(300)]]);
        $this->assertSame(1, app(SecurityLog::class)->prune());
        $this->assertSame(1, DB::table('security_events')->count());
    }
}
