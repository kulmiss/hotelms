<?php

namespace App\Providers;

use App\Models\CashierSession;
use App\Models\HousekeepingTask;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\UserDevice;
use App\Services\Automation\AutomationEvents;
use App\Services\Automation\Automations;
use App\Services\Modules\Housekeeping;
use App\Services\PlatformPermissions;
use App\Services\Switches;
use App\Support\Tenancy\TenantContext;
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
        $this->automationHooks();

        // Development: write slow database work to the log, so slow screens are found early.
        if (! $this->app->isProduction()) {
            DB::whenQueryingForLongerThan(1000, fn ($connection, QueryExecuted $event) => Log::warning('Slow request: '.round($event->time).' ms on one query', ['sql' => $event->sql]));
            Model::handleLazyLoadingViolationUsing(fn ($model, string $relation) => Log::info('Loaded one by one (add ->with()): '.$model::class.'::'.$relation));
            Model::preventLazyLoading(! $this->app->runningUnitTests());
        }

        // Slow down password guessing: 5 tries a minute per email and device.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(
            strtolower((string) $request->input('email')).'|'.$request->ip()
        ));
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
