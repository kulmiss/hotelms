<?php

namespace App\Services;

use App\Mail\HotelInvoiceMail;
use App\Models\Hotel;
use App\Models\HotelInvoice;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Subscription invoices to hotels (super-admin.md area 3): made a week before the paid period ends,
 * emailed to the Hotel Admins, and marked paid when the payment is recorded.
 */
class HotelBilling
{
    public function __construct(private Subscriptions $subscriptions) {}

    /** The next period to bill: from the end of what is paid (or the trial), for one billing cycle. */
    public function nextPeriod(Hotel $hotel): array
    {
        $today = Carbon::today();
        $ends = $hotel->status === 'trial' ? $hotel->trial_ends_at : $hotel->subscription_ends_at;
        $start = $ends && $ends->gte($today) ? $ends->copy()->addDay() : $today;
        $months = $hotel->billing_cycle === 'yearly' ? 12 : 1;

        return [$start, $start->copy()->addMonthsNoOverflow($months)->subDay()];
    }

    public function issue(Hotel $hotel, ?User $by = null, bool $email = true): HotelInvoice
    {
        $plan = $hotel->plan ?? $hotel->trialPlan;
        if (! $plan) {
            throw ValidationException::withMessages(['plan' => 'Choose a plan for this hotel first.']);
        }
        [$start, $end] = $this->nextPeriod($hotel);
        $existing = HotelInvoice::where('hotel_id', $hotel->id)->where('status', '!=', 'void')->whereDate('period_start', $start)->first();
        if ($existing) {
            return $existing; // one invoice per period
        }
        $cycle = $hotel->billing_cycle === 'yearly' ? 'yearly' : 'monthly';

        $invoice = Cache::lock('hotel-invoice-number', 10)->block(10, fn () => DB::transaction(function () use ($hotel, $plan, $start, $end, $cycle, $by) {
            $year = now()->format('Y');
            $last = HotelInvoice::where('number', 'like', "HMS-{$year}-%")->orderByDesc('id')->value('number');
            $next = $last ? ((int) substr($last, -5)) + 1 : 1;

            return HotelInvoice::create([
                'hotel_id' => $hotel->id, 'number' => sprintf('HMS-%s-%05d', $year, $next), 'plan_id' => $plan->id, 'billing_cycle' => $cycle,
                'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'rooms' => $this->subscriptions->billableRooms($hotel),
                'amount' => Money::round($this->subscriptions->quote($hotel, $plan, $cycle, 1)), 'currency' => $plan->currency ?? 'ETB', 'status' => 'unpaid',
                // Due on the first day of the new period (never in the past).
                'due_date' => max($start->toDateString(), now()->toDateString()), 'created_by' => $by?->id,
            ]);
        }));
        if ($email) {
            $this->send($invoice);
        }

        return $invoice;
    }

    /** Emails the invoice to the Hotel Admins and the hotel's own address. Returns false when nobody got it. */
    public function send(HotelInvoice $invoice): bool
    {
        $hotel = $invoice->hotel;
        if (! $hotel || $hotel->is_demo) {
            return false;
        }
        $to = User::where('hotel_id', $hotel->id)->where('status', 'active')->with('role')->get()->filter(fn (User $u) => $u->isHotelAdmin())
            ->pluck('email')->push($hotel->email)->filter()->unique()->values();
        if ($to->isEmpty()) {
            return false;
        }
        try {
            Mail::to($to->all())->send(new HotelInvoiceMail($invoice));
            $invoice->forceFill(['emailed_at' => now()])->save();

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** A recorded payment pays the oldest unpaid invoice it covers. */
    public function markPaid(SubscriptionPayment $payment): void
    {
        HotelInvoice::where('hotel_id', $payment->hotel_id)->where('status', 'unpaid')
            ->whereDate('period_start', '<=', $payment->period_start)->orderBy('period_start')->first()
            ?->update(['status' => 'paid', 'subscription_payment_id' => $payment->id]);
    }

    /** Daily: invoices for hotels whose paid period (or trial) ends within a week. */
    public function issueDue(): int
    {
        $count = 0;
        Hotel::where('is_demo', false)->whereIn('status', ['trial', 'active', 'grace'])->whereNotNull('plan_id')
            ->where(fn ($q) => $q->where(fn ($a) => $a->where('status', '!=', 'trial')->whereDate('subscription_ends_at', '<=', now()->addDays(7)))
                ->orWhere(fn ($t) => $t->where('status', 'trial')->whereDate('trial_ends_at', '<=', now()->addDays(7))))
            ->get()->each(function (Hotel $hotel) use (&$count) {
                if ($hotel->plan?->isFree()) {
                    return; // nothing to bill
                }
                try {
                    $invoice = $this->issue($hotel);
                    $count += $invoice->wasRecentlyCreated ? 1 : 0;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return $count;
    }
}
