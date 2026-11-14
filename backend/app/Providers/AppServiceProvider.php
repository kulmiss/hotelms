<?php

namespace App\Providers;

use App\Models\CashierSession;
use App\Models\HousekeepingTask;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Automation\AutomationEvents;
use App\Services\Automation\Automations;
use App\Services\Modules\Housekeeping;
use App\Services\Notify\MailSettings;
use App\Services\PlatformPermissions;
use App\Services\Security\SecurityLog;
use App\Services\Switches;
use App\Support\Tenancy\TenantContext;
use App\Support\TrustedProxies;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant context per request / job.
        $this->app->scoped(TenantContext::class);
        // Switch values are read once per request / job.
        $this->app->scoped(Switches::class);
        $this->app->scoped(PlatformPermissions::class);
        $this->app->scoped(Automations::class);
    }

    public function boot(): void
    {
        // The mail server saved in Super Admin → Settings (if any), applied only when an email is sent.
        $this->app->resolving('mail.manager', fn () => MailSettings::apply());
        // Behind a proxy or CDN only: trust its X-Forwarded-* headers (TRUSTED_PROXIES; empty = trust nobody).
        TrustedProxies::apply(config('hotelms.trusted_proxies'));
        $this->automationHooks();

        // Development: write slow database work to the log, so slow screens are found early.
        if (! $this->app->isProduction()) {
            DB::whenQueryingForLongerThan(1000, fn ($connection, QueryExecuted $event) => Log::warning('Slow request: '.round($event->time).' ms on one query', ['sql' => $event->sql]));
            Model::handleLazyLoadingViolationUsing(fn ($model, string $relation) => Log::info('Loaded one by one (add ->with()): '.$model::class.'::'.$relation));
            Model::preventLazyLoading(! $this->app->runningUnitTests());
        }

        // Slow down password guessing: 5 tries a minute per email and device.
        // Per email + address, and per address alone (trying many emails from one place).
        // General limit for every signed-in call (SECURITY_REPORT M5): a dashboard load is about 12 calls,
        // live screens poll every few seconds – far below this. One stolen session cannot pull data endlessly.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)->by('api|'.($request->user()?->id ?? $request->ip()))
            ->response(fn () => response()->json(['message' => 'Too many requests in a short time. Wait a minute and try again.', 'code' => 'too_many'], 429)));
        // Hotel sign-up (no captcha, no cookies): 5 an hour and 20 a day from one address.
        RateLimiter::for('signup', fn (Request $request) => [
            Limit::perHour(5)->by('signup|'.$request->ip()),
            Limit::perDay(20)->by('signup-day|'.$request->ip()),
        ]);
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower(is_string($request->input('email')) ? $request->input('email') : '').'|'.$request->ip()),
            Limit::perMinute(30)->by('login-ip|'.$request->ip()),
        ]);
        // Codes and PINs are short, so guess attempts are tightly limited (per session and per address).
        RateLimiter::for('two-factor', fn (Request $request) => [
            Limit::perMinute(5)->by('2fa|'.($request->hasSession() ? $request->session()->getId() : '').'|'.$request->ip()),
            Limit::perHour(30)->by('2fa-ip|'.$request->ip()),
        ]);
        // Per hotel (staff share one network), so a PIN cannot be found by trying them all.
        RateLimiter::for('pin', fn (Request $request) => [
            Limit::perMinute(5)->by('pin|'.($request->user()?->hotel_id ?? $request->ip())),
            Limit::perHour(30)->by('pin-h|'.($request->user()?->hotel_id ?? $request->ip())),
        ]);
    }

    /**
     * Automations react to changes after they are saved for good (after the database transaction),
     * using a copy of the model as it was at that moment.
     */
    private function automationHooks(): void
    {
        $after = fn (string $method) => function ($model) use ($method) {
            $copy = clone $model;
            DB::afterCommit(fn () => app(AutomationEvents::class)->{$method}($copy));
        };
        Reservation::created($after('reservationCreated'));
        Reservation::updated($after('reservationUpdated'));
        Payment::created($after('paymentCreated'));
        // Refunds also go to the security event log (SECURITY_REPORT M9).
        Payment::created(function (Payment $p) {
            if ($p->type === 'refund') {
                app(SecurityLog::class)->record('refund', User::find($p->received_by), null, null, "{$p->amount} {$p->currency}, booking #{$p->reservation_id}: {$p->refund_reason}");
            }
        });
        Invoice::updated($after('invoiceUpdated'));
        InvoiceItem::updated($after('invoiceItemUpdated'));
        CashierSession::updated($after('cashierUpdated'));
        Room::updated($after('roomUpdated'));
        // A room that becomes dirty always gets a cleaning task (housekeepers see it in their list).
        Room::updated(function (Room $room) {
            if ($room->wasChanged('status') && $room->status === 'dirty') {
                app(Housekeeping::class)->taskForDirtyRoom($room);
            }
            // Marked clean by hand (e.g. at reception): its open cleaning task is finished, not left behind.
            if ($room->wasChanged('status') && $room->status === 'available') {
                app(Housekeeping::class)->closeCleaningTasks($room);
            }
        });
        HousekeepingTask::created($after('taskCreated'));
        UserDevice::created($after('deviceCreated'));
    }
}
