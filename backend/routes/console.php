<?php

use App\Http\Controllers\Api\Admin\SystemController;
use App\Models\Hotel;
use App\Models\PaymentProof;
use App\Services\GuestIdPhotos;
use App\Services\Security\SecurityLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

/*
| Scheduled jobs. On shared hosting, one cron line runs them every minute:
|   * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
*/

Schedule::command('hotels:update-statuses')->hourly()->withoutOverlapping();
Schedule::command('reservations:expire')->everyMinute()->withoutOverlapping();
Schedule::command('stays:roll-open')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('reports:automation')->everyMinute()->withoutOverlapping();
Schedule::command('subscriptions:invoices')->dailyAt('07:10')->withoutOverlapping();
Schedule::command('automations:run')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('reports:automation --alerts')->everyFifteenMinutes()->withoutOverlapping();

// Queued work, if any (no always-on worker needed).
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();

// Backups: full every night, small every hour, checked every month (decisions.md W7).
Schedule::command('hotelms:backup')->dailyAt('01:15')->withoutOverlapping();
Schedule::command('hotelms:backup --small')->hourlyAt(40)->withoutOverlapping();
Schedule::command('hotelms:backup --check')->monthlyOn(1, '06:00');
Schedule::command('hotelms:purge-closed-hotels')->dailyAt('03:30')->withoutOverlapping();

// The demo hotel starts fresh every night.
Schedule::command('demo:reset')->dailyAt('02:30')->withoutOverlapping();

// Proof that cron runs (Super Admin → System health).
Schedule::call(fn () => Cache::forever(SystemController::HEARTBEAT, now()->toIso8601String()))->everyMinute()->name('scheduler-heartbeat');

// Payment proofs uploaded but never attached to a payment (the payment was not saved): removed after a day.
Schedule::call(function () {
    app(TenantContext::class)->acrossAllHotels(fn () => PaymentProof::whereNull('payment_id')->where('created_at', '<', now()->subDay())->get()
        ->each(function (PaymentProof $p) {
            Storage::disk('local')->delete($p->path);
            $p->delete();
        }));
})->name('payment-proofs:cleanup')->dailyAt('03:20')->withoutOverlapping();

// Saved-once keys (Idempotent middleware): kept 24 hours, removed after 48.
Schedule::call(fn () => DB::table('idempotency_keys')->where('created_at', '<', now()->subHours(48))->delete())
    ->name('idempotency-keys:cleanup')->hourlyAt(25)->withoutOverlapping();

// Guest ID photos past the time each hotel keeps them (Booking rules): removed at night.
Schedule::call(function () {
    Hotel::whereIn('status', ['trial', 'active', 'grace', 'suspended'])->get()
        ->each(fn (Hotel $hotel) => app(TenantContext::class)->runAs($hotel, fn () => app(GuestIdPhotos::class)->cleanUp($hotel)));
})->name('guest-id-photos:cleanup')->dailyAt('03:40')->withoutOverlapping();

// Super Admin watchdog: security, bugs and data leaks (quick checks every 10 minutes, data checks every hour).
// Security event log: kept 1 year (SECURITY_REPORT M9).
Schedule::call(fn () => app(SecurityLog::class)->prune())->name('security-events:prune')->dailyAt('03:50')->withoutOverlapping();
Schedule::command('watchdog:run')->everyTenMinutes()->withoutOverlapping();
Schedule::command('watchdog:run --data')->hourlyAt(17)->withoutOverlapping();
