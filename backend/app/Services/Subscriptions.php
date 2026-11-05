<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\Plan;
use App\Models\Room;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hotel payments to HotelMS. A payment extends the hotel's paid-until date
 * and reactivates it immediately (decisions.md → Billing).
 */
class Subscriptions
{
    public function __construct(private HotelStatus $status) {}

    /** Months covered by a payment: yearly = 12 per period. */
    public function monthsCovered(string $cycle, int $periods): int
    {
        return $cycle === 'yearly' ? 12 * $periods : $periods;
    }

    /**
     * Suggested amount for a payment. Rooms are counted from phase 2;
     * until then the plan minimum applies.
     */
    public function quote(Hotel $hotel, Plan $plan, string $cycle, int $periods): string
    {
        if ($plan->is_custom && $hotel->custom_price !== null) {
            $months = $cycle === 'yearly' ? (12 - $plan->yearly_free_months) * $periods : $periods;

            return Money::mul($hotel->custom_price, (string) $months);
        }

        return $plan->priceFor($this->billableRooms($hotel), $cycle, $periods);
    }

    /** Active rooms across all branches (plans.md: counted on the invoice date). */
    public function billableRooms(Hotel $hotel): int
    {
        return Room::withoutGlobalScope('hotel')->where('hotel_id', $hotel->id)->count();
    }

    /**
     * @param  array{plan_id: int, amount: string, currency?: string, method: string, reference?: string|null,
     *               billing_cycle: string, periods: int, paid_at?: string|null, notes?: string|null}  $data
     */
    public function recordPayment(Hotel $hotel, array $data, User $recordedBy): SubscriptionPayment
    {
        return DB::transaction(function () use ($hotel, $data, $recordedBy) {
            $hotel = Hotel::lockForUpdate()->findOrFail($hotel->id);
            $months = $this->monthsCovered($data['billing_cycle'], (int) $data['periods']);

            $today = Carbon::today();
            $start = $hotel->subscription_ends_at && $hotel->subscription_ends_at->gte($today)
                ? $hotel->subscription_ends_at->copy()->addDay()
                : $today;
            $end = $start->copy()->addMonthsNoOverflow($months)->subDay();

            $payment = SubscriptionPayment::create([
                'hotel_id' => $hotel->id,
                'plan_id' => $data['plan_id'],
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'ETB',
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'billing_cycle' => $data['billing_cycle'],
                'months' => $months,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'paid_at' => $data['paid_at'] ?? now(),
                'recorded_by' => $recordedBy->id,
                'notes' => $data['notes'] ?? null,
            ]);

            $hotel->forceFill([
                'plan_id' => $data['plan_id'],
                'billing_cycle' => $data['billing_cycle'],
                'subscription_ends_at' => $end->toDateString(),
            ])->save();
            $this->status->refresh($hotel);

            return $payment;
        });
    }
}
