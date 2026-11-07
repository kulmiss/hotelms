<?php

namespace App\Models\Concerns;

use App\Services\ActivityLogger;

/**
 * Records created / updated / deleted in the activity log, with the changed
 * fields (old → new). Passwords and tokens are never stored.
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(function ($model) {
            app(ActivityLogger::class)->log('created', self::activityLabel($model).' added: '.$model->activityName(), $model, [
                'new' => $model->activityAttributes($model->getAttributes()),
            ]);
        });

        static::updated(function ($model) {
            $changes = $model->activityAttributes($model->getChanges());
            if ($changes === []) {
                return;
            }
            $old = array_intersect_key($model->getOriginal(), $changes);
            app(ActivityLogger::class)->log('updated', self::activityLabel($model).' changed: '.$model->activityName(), $model, [
                'old' => $model->activityAttributes($old),
                'new' => $changes,
            ]);
        });

        static::deleted(function ($model) {
            app(ActivityLogger::class)->log('deleted', self::activityLabel($model).' removed: '.$model->activityName(), $model);
        });
    }

    /** Plain words for the activity log and "My shift" (staff read these; never class names like "InvoiceItem"). */
    private const ACTIVITY_LABELS = [
        'AlertEvent' => 'Alert', 'AlertRule' => 'Alert rule', 'Attendance' => 'Attendance', 'Branch' => 'Branch', 'CancellationPolicy' => 'Cancellation policy',
        'CashierSession' => 'Cashier shift', 'ChargeType' => 'Charge', 'CustomReport' => 'Own report', 'ExchangeRate' => 'Exchange rate', 'Guest' => 'Guest',
        'GuestType' => 'Guest type', 'Hotel' => 'Hotel', 'HotelCurrency' => 'Currency', 'HotelModule' => 'Module', 'HotelSetting' => 'Setting',
        'HousekeepingTask' => 'Cleaning task', 'Import' => 'Import', 'Invoice' => 'Bill', 'InvoiceItem' => 'Bill line', 'Payment' => 'Payment',
        'PaymentMethod' => 'Payment method', 'Plan' => 'Plan', 'RateRule' => 'Price rule', 'ReportSchedule' => 'Scheduled report', 'ReportTarget' => 'Target',
        'Reservation' => 'Booking', 'ReservationRoom' => 'Booked room', 'Role' => 'Role', 'Room' => 'Room', 'RoomType' => 'Room type',
        'SavedView' => 'Saved view', 'Shift' => 'Shift', 'ShiftAssignment' => 'Roster', 'Tax' => 'Tax', 'User' => 'Staff member',
    ];

    public static function activityLabel(object $model): string
    {
        $class = class_basename($model);

        return self::ACTIVITY_LABELS[$class] ?? trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $class));
    }

    public function activityName(): string
    {
        return (string) ($this->getAttribute('name') ?? $this->getAttribute('email') ?? '#'.$this->getKey());
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function activityAttributes(array $attributes): array
    {
        return array_diff_key($attributes, array_flip([
            'password', 'remember_token', 'attendance_pin', 'two_factor_secret', 'two_factor_recovery_codes', 'updated_at', 'created_at', 'last_login_at',
            // Bookkeeping by the system itself (checked every few minutes): not a change anyone made.
            'last_checked_at', 'next_run_at', 'last_run_at', 'last_sent_at', 'last_error', 'last_seen_at', 'last_fired_at', 'fired_count',
        ]));
    }
}
