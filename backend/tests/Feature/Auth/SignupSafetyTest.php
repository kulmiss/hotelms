<?php

namespace Tests\Feature\Auth;

use App\Models\Branch;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Plan;
use App\Models\User;
use App\Services\DefaultRoles;
use App\Services\HotelDefaults;
use App\Services\Notify\Notifier;
use App\Services\Signup\SignupCleanup;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Safety around self sign-up (owner, 14 Nov 2026): nothing is sent before confirmation, cleanup, inactive Free hotels. */
class SignupSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function hotel(array $attrs = []): Hotel
    {
        $hotel = Hotel::factory()->create($attrs + ['plan_id' => Plan::where('code', 'free')->value('id'), 'status' => 'active']);
        app(DefaultRoles::class)->ensureFor($hotel);
        app(HotelDefaults::class)->apply($hotel);
        Branch::factory()->for($hotel)->create();

        return $hotel;
    }

    public function test_nothing_is_sent_for_a_hotel_that_is_not_confirmed_or_approved(): void
    {
        Mail::fake();
        foreach (['unverified', 'pending', 'rejected'] as $state) {
            $hotel = $this->hotel(['signup_status' => $state]);
            $problems = app(Notifier::class)->send($hotel, [], ['email', 'telegram'], ['someone@guest.test'], 'Daily report', ['x']);
            $this->assertNotEmpty($problems);
            $this->assertFalse($hotel->maySendMessages());
        }
        Mail::assertNothingSent();

        $ok = $this->hotel(['signup_status' => 'approved']);
        app(Notifier::class)->send($ok, [], ['email'], ['someone@guest.test'], 'Daily report', ['x']);
        Mail::assertSentCount(1);
    }

    public function test_unconfirmed_sign_ups_are_removed_after_7_days_and_others_stay(): void
    {
        $old = $this->hotel(['signup_status' => 'unverified']);
        $old->forceFill(['created_at' => now()->subDays(8)])->save();
        $owner = User::factory()->withRole($old, 'hotel_admin')->create();
        $fresh = $this->hotel(['signup_status' => 'unverified']);
        $team = $this->hotel(['signup_status' => null]);
        $team->forceFill(['created_at' => now()->subDays(30)])->save();

        $r = app(SignupCleanup::class)->run();
        $this->assertSame(1, $r['removed']);
        $this->assertNull(Hotel::withTrashed()->find($old->id));
        $this->assertNull(User::withTrashed()->find($owner->id));
        $this->assertNotNull(Hotel::find($fresh->id));
        $this->assertNotNull(Hotel::find($team->id));
    }

    public function test_guest_data_is_never_deleted_automatically(): void
    {
        $old = $this->hotel(['signup_status' => 'unverified']);
        $old->forceFill(['created_at' => now()->subDays(8)])->save();
        app(TenantContext::class)->runAs($old, fn () => Guest::create(['first_name' => 'Abebe']));

        $r = app(SignupCleanup::class)->run();
        $this->assertSame(['removed' => 0, 'kept' => 1], array_intersect_key($r, ['removed' => 0, 'kept' => 0]));
        $this->assertNotNull(Hotel::find($old->id));
    }

    public function test_free_hotels_unused_for_90_days_are_flagged_not_deleted(): void
    {
        $idle = $this->hotel();
        $idle->forceFill(['created_at' => now()->subDays(200)])->save();
        $admin = User::factory()->withRole($idle, 'hotel_admin')->create(['last_login_at' => now()->subDays(120)]);
        $busy = $this->hotel();
        $busy->forceFill(['created_at' => now()->subDays(200)])->save();
        User::factory()->withRole($busy, 'hotel_admin')->create(['last_login_at' => now()->subDays(3)]);
        $paid = $this->hotel(['plan_id' => Plan::where('code', 'starter')->value('id')]);
        $paid->forceFill(['created_at' => now()->subDays(200)])->save();

        app(SignupCleanup::class)->run();
        $this->assertNotNull($idle->fresh()->inactive_since);
        $this->assertNull($busy->fresh()->inactive_since);
        $this->assertNull($paid->fresh()->inactive_since); // only Free hotels

        $team = User::factory()->superAdmin()->create();
        $this->actingAs($team)->getJson('/api/v1/admin/hotels?inactive=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $idle->id);
        $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('inactive_free.0.id', $idle->id);

        // Signing in again clears the flag.
        auth()->guard('web')->logout();
        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $this->assertNull($idle->fresh()->inactive_since);
    }

    public function test_the_cleanup_runs_from_the_scheduler(): void
    {
        $this->artisan('hotelms:signup-cleanup')->assertSuccessful();
        $events = collect(app(Schedule::class)->events())->map->command->implode(' ');
        $this->assertStringContainsString('hotelms:signup-cleanup', $events);
    }
}
