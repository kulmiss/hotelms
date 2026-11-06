<?php

namespace App\Services\Booking;

use App\Models\Branch;
use App\Models\CancellationPolicy;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\NumberSequence;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Billing\CurrencyConverter;
use App\Services\Billing\InvoiceService;
use App\Services\HotelSettings;
use App\Services\Modules\Cashier;
use App\Services\Switches;
use App\Support\HotelClock;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reservations (workflows.md W1, W2): price, availability, deposit,
 * pending → confirmed, cancel and no-show with the booking's policy.
 * Everything runs inside the hotel's tenant context.
 */
class ReservationService
{
    public function __construct(
        private Availability $availability,
        private StayQuote $quote,
        private HotelSettings $settings,
        private CurrencyConverter $converter,
    ) {}

    /**
     * Price and availability without saving (the "price preview").
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function preview(array $data, User $user, ?Reservation $existing = null): array
    {
        $plan = $this->plan($data, $user, $existing, true);
        unset($plan['branch'], $plan['guest'], $plan['policy'], $plan['types'], $plan['check_in_at_utc'], $plan['check_out_at_utc'], $plan['blocked_until_utc']);

        return $plan;
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $user): Reservation
    {
        return DB::transaction(function () use ($data, $user) {
            $this->lockTypes($data);
            $plan = $this->plan($data, $user, null);
            $hotel = $user->hotel;
            $payLater = ! empty($data['pay_later']);
            if ($payLater) {
                $this->assertPayLater($user);
            }
            $pending = ! $payLater && Money::isPositive($plan['deposit_required']);

            $reservation = Reservation::create([
                'branch_id' => $plan['branch']->id,
                'code' => $this->code($plan['branch'], $hotel),
                'guest_id' => $plan['guest']->id,
                'source' => $data['source'] ?? 'phone',
                'stay_type' => $plan['stay_type'],
                'check_in_at' => $plan['check_in_at_utc'],
                'check_out_at' => $plan['check_out_at_utc'],
                'adults' => $plan['adults'],
                'children' => $plan['children'],
                'status' => $pending ? 'pending' : 'confirmed',
                'expires_at' => $pending ? now()->addHours((int) $this->settings->group(HotelSettings::BOOKING_DEFAULTS)['pending_expiry_hours']) : null,
                'cancellation_policy_id' => $plan['policy']?->id,
                'policy_snapshot' => $plan['policy']?->only(['name', 'free_until_hours', 'penalty_type', 'penalty_value', 'applies_to_no_show']),
                'currency' => $plan['currency'],
                'subtotal' => $plan['subtotal'],
                'discount_percent' => $plan['discount_percent'],
                'discount_amount' => $plan['discount_amount'],
                'tax_total' => $plan['tax_total'],
                'total' => $plan['total'],
                'deposit_required' => $plan['deposit_required'],
                'paid_total' => '0.00',
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
                'pay_later_by' => $payLater ? $user->id : null,
                'pay_later_at' => $payLater ? now() : null,
            ]);
            $this->saveRooms($reservation, $plan);

            return $reservation->fresh();
        });
    }

    /** Change dates, rooms, guest or discount until check-in; the price is recalculated, the deposit stays (Q3). */
    public function update(Reservation $reservation, array $data, User $user): Reservation
    {
        if (! $reservation->isEditable()) {
            throw ValidationException::withMessages(['status' => 'Only pending or confirmed bookings can be changed.']);
        }

        return DB::transaction(function () use ($reservation, $data, $user) {
            $this->lockTypes($data);
            $data['branch_id'] = $reservation->branch_id; // a booking stays in its branch
            $plan = $this->plan($data, $user, $reservation);

            $changes = [
                'guest_id' => $plan['guest']->id,
                'source' => $data['source'] ?? $reservation->source,
                'stay_type' => $plan['stay_type'],
                'check_in_at' => $plan['check_in_at_utc'],
                'check_out_at' => $plan['check_out_at_utc'],
                'adults' => $plan['adults'],
                'children' => $plan['children'],
                'subtotal' => $plan['subtotal'],
                'discount_percent' => $plan['discount_percent'],
                'discount_amount' => $plan['discount_amount'],
                'tax_total' => $plan['tax_total'],
                'total' => $plan['total'],
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $reservation->notes,
            ];
            if ($reservation->status === 'pending') {
                $changes['deposit_required'] = $plan['deposit_required'];
            }
            if ($plan['policy'] && $plan['policy']->id !== $reservation->cancellation_policy_id) {
                $changes['cancellation_policy_id'] = $plan['policy']->id;
                $changes['policy_snapshot'] = $plan['policy']->only(['name', 'free_until_hours', 'penalty_type', 'penalty_value', 'applies_to_no_show']);
            }
            $reservation->update($changes);

            $reservation->rooms()->each(fn (ReservationRoom $r) => $r->delete());
            $this->saveRooms($reservation, $plan);
            $this->confirmIfPaid($reservation->refresh());

            return $reservation->fresh();
        });
    }

    /**
     * Record a deposit, payment or refund (W9: any currency, converted with the latest rate).
     *
     * @param  array<string, mixed>  $data
     */
    public function recordPayment(Reservation $reservation, array $data, User $user, ?Invoice $invoice = null): Payment
    {
        $type = $data['type'];
        if (! empty($data['currency']) && strtoupper($data['currency']) !== $reservation->currency && ! app(Switches::class)->on($user->hotel, 'feature.multi_currency')) {
            throw ValidationException::withMessages(['currency' => 'Payments in other currencies are not available for your hotel.']);
        }
        // After check-in, money goes on the guest's bill.
        $invoice ??= $reservation->invoices()->where('status', '!=', 'void')->orderBy('id')->first();
        $owing = $invoice && Money::isPositive((string) $invoice->balance);
        if ($type !== 'refund' && ! $reservation->isActive() && ! $owing) {
            throw ValidationException::withMessages(['type' => 'This booking is '.$reservation->status.'; only refunds are possible.']);
        }
        $method = PaymentMethod::where('is_active', true)->find($data['payment_method_id']);
        if (! $method) {
            throw ValidationException::withMessages(['payment_method_id' => 'Choose a payment method.']);
        }

        $amount = Money::round((string) $data['amount']);
        $currency = strtoupper($data['currency'] ?? $reservation->currency);
        $rate = $this->converter->rate($currency, $reservation->currency);
        $inBase = Money::mul($amount, $rate);

        $paidSoFar = $invoice ? (string) $invoice->paid_total : (string) $reservation->paid_total;
        if ($type === 'refund' && Money::compare($inBase, $paidSoFar) > 0) {
            throw ValidationException::withMessages(['amount' => 'A refund cannot be more than what was paid ('.$paidSoFar.' '.$reservation->currency.').']);
        }

        return DB::transaction(function () use ($reservation, $data, $user, $method, $type, $amount, $currency, $rate, $inBase, $invoice) {
            $payment = Payment::create([
                'branch_id' => $reservation->branch_id,
                'reservation_id' => $reservation->id,
                'invoice_id' => $invoice?->id,
                'payment_method_id' => $method->id,
                'cashier_session_id' => app(Cashier::class)->sessionForPayment($user, $method, $reservation->branch_id),
                'type' => $type,
                'amount' => $amount,
                'currency' => $currency,
                'exchange_rate' => $rate,
                'amount_in_base' => $inBase,
                'reference' => $data['reference'] ?? null,
                'refund_reason' => $type === 'refund' ? $data['refund_reason'] : null,
                'paid_at' => now(),
                'received_by' => $user->id,
            ]);
            $this->refreshPaid($reservation);
            $this->confirmIfPaid($reservation->refresh());
            if ($invoice) {
                app(InvoiceService::class)->recalc($invoice);
            }

            return $payment;
        });
    }

    /**
     * One payment for a booking with several bills (split per room): it pays the bills in order,
     * as separate payment lines, so no bill is left owing while another is overpaid.
     *
     * @param  array<string, mixed>  $data
     */
    public function payAcrossBills(Reservation $reservation, array $data, User $user): void
    {
        $bills = $reservation->invoices()->where('status', '!=', 'void')->orderBy('id')->get();
        if ($bills->count() < 2 || ($data['type'] ?? 'payment') === 'refund') {
            $this->recordPayment($reservation, $data, $user, $bills->first());

            return;
        }
        $currency = strtoupper($data['currency'] ?? $reservation->currency);
        $rate = $this->converter->rate($currency, $reservation->currency);
        $left = Money::round((string) $data['amount']);
        $owing = $bills->filter(fn (Invoice $i) => Money::isPositive((string) $i->balance))->values();
        $targets = $owing->isNotEmpty() ? $owing : collect([$bills->first()]);

        foreach ($targets as $k => $bill) {
            if (! Money::isPositive($left)) {
                break;
            }
            $last = $k === $targets->count() - 1;
            // The bill's balance in the paid currency (rounded up to the cent so it is fully covered).
            $need = Money::round(bcadd(bcdiv((string) $bill->balance, $rate, 6), '0.004999', 6));
            $part = $last ? $left : Money::min($need, $left);
            $this->recordPayment($reservation, ['amount' => $part] + $data, $user, $bill->fresh());
            $left = Money::sub($left, $part);
        }
    }

    /**
     * What cancelling (or a no-show) would cost under the booking's policy (W2).
     *
     * @return array{fee: string, refund_due: string, free: bool, hours_before: int, policy: ?array<string, mixed>}
     */
    public function cancellationTerms(Reservation $reservation, bool $noShow = false): array
    {
        $policy = $reservation->policy_snapshot;
        $hoursBefore = (int) floor(now()->diffInMinutes($reservation->check_in_at, false) / 60);
        $paid = (string) $reservation->paid_total;

        if (! $policy) {
            return ['fee' => '0.00', 'refund_due' => $paid, 'free' => true, 'hours_before' => $hoursBefore, 'policy' => null];
        }

        $free = $noShow
            ? ! $policy['applies_to_no_show']
            : $policy['free_until_hours'] !== null && $hoursBefore >= (int) $policy['free_until_hours'];

        $fee = '0.00';
        if (! $free) {
            $value = (string) $policy['penalty_value'];
            $fee = match ($policy['penalty_type']) {
                'nights' => $this->firstNights($reservation, (int) ceil((float) $value)),
                'fixed' => Money::round($value),
                default => Money::percentOf((string) $reservation->total, $value),
            };
            $fee = Money::min($fee, (string) $reservation->total);
        }

        return [
            'fee' => $fee,
            'refund_due' => Money::max(Money::sub($paid, $fee), '0'),
            'free' => $free,
            'hours_before' => $hoursBefore,
            'policy' => $policy,
        ];
    }

    public function cancel(Reservation $reservation, string $reason, ?User $user, bool $noShow = false, bool $chargeFee = true): Reservation
    {
        if (! $reservation->isEditable()) {
            throw ValidationException::withMessages(['status' => 'Only pending or confirmed bookings can be cancelled.']);
        }
        if ($noShow && now()->lt($reservation->check_in_at)) {
            throw ValidationException::withMessages(['status' => 'A no-show can only be marked after the check-in time.']);
        }
        $terms = $this->cancellationTerms($reservation, $noShow);
        $status = $noShow ? 'no_show' : 'cancelled';

        DB::transaction(function () use ($reservation, $reason, $user, $terms, $status, $chargeFee) {
            $reservation->update([
                'status' => $status,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                'cancelled_by' => $user?->id,
                'cancellation_fee' => $chargeFee ? $terms['fee'] : '0.00',
                'expires_at' => null,
            ]);
            $reservation->rooms()->update(['status' => $status]); // rooms freed
        });

        return $reservation->fresh();
    }

    /** Pending bookings past their time are cancelled (Q2). Returns how many. */
    public function expireDue(): int
    {
        $count = 0;
        Reservation::where('status', 'pending')->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->get()->each(function (Reservation $r) use (&$count) {
                // The same lock as a person changing this booking (OneAtATime): a deposit paid at
                // this very moment wins, and the booking is then no longer pending.
                $lock = Cache::lock("one-at-a-time:reservation:{$r->hotel_id}:{$r->id}", 30);
                if (! $lock->get()) {
                    return; // someone is working on it: try again next minute
                }
                try {
                    $fresh = $r->fresh();
                    if ($fresh && $fresh->status === 'pending' && $fresh->expires_at?->lte(now())) {
                        $this->cancel($fresh, 'expired', null, false, false);
                        $count++;
                    }
                } finally {
                    $lock->release();
                }
            });

        return $count;
    }

    // ------------------------------------------------------------------ internals

    /**
     * Check everything and work out prices.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function plan(array $data, User $user, ?Reservation $existing, bool $preview = false): array
    {
        $hotel = $user->hotel;
        // Super Admin switches (W4): hourly stays and day use can be off for a plan or hotel.
        $switch = ['hourly' => 'feature.hourly_stays', 'day_use' => 'feature.day_use'][$data['stay_type'] ?? ''] ?? null;
        if ($switch && ! app(Switches::class)->on($hotel, $switch)) {
            throw ValidationException::withMessages(['stay_type' => 'This kind of stay is not available for your hotel.']);
        }
        $branch = $user->accessibleBranches()->firstWhere('id', (int) $data['branch_id']);
        if (! $branch) {
            throw ValidationException::withMessages(['branch_id' => 'Choose one of your branches.']);
        }
        $stayType = $data['stay_type'] ?? 'nightly';
        $times = $this->quote->times($hotel, $branch, $stayType, (string) $data['check_in'], $data['check_out'] ?? null, isset($data['hours']) ? (int) $data['hours'] : null);

        if (! empty($data['walk_in'])) {
            $times['in'] = now()->startOfMinute(); // walk-ins start now
        } elseif (empty($data['allow_past']) && $times['in']->lt(now()->subMinutes(30)) && (! $existing || ! $existing->check_in_at->eq($times['in']))) {
            throw ValidationException::withMessages(['check_in' => 'Check-in cannot be in the past.']);
        }

        $guest = Guest::find($data['guest_id'] ?? 0);
        if (! $guest && ! $preview) {
            throw ValidationException::withMessages(['guest_id' => 'Choose or add the guest.']);
        }
        $guestTypeId = $guest ? $guest->guest_type_id : (isset($data['guest_type_id']) ? (int) $data['guest_type_id'] : null);
        if ($guest?->is_blacklisted) {
            throw ValidationException::withMessages(['guest_id' => 'This guest is blacklisted'.($guest->blacklist_reason ? ': '.$guest->blacklist_reason : '.')]);
        }

        $policy = $this->policy($data, $user, $existing);
        $discount = Money::round((string) ($data['discount_percent'] ?? '0'));
        $max = (string) ($user->role?->max_discount_percent ?? '0');
        if (Money::compare($discount, '0') < 0 || Money::compare($discount, '100') > 0) {
            throw ValidationException::withMessages(['discount_percent' => 'Between 0 and 100.']);
        }
        if (Money::compare($discount, $max) > 0 && ! ($existing && Money::compare($discount, (string) $existing->discount_percent) === 0)) {
            throw ValidationException::withMessages(['discount_percent' => "Your role allows discounts up to {$max}%."]);
        }

        $requested = collect($data['rooms'] ?? []);
        if ($requested->isEmpty()) {
            throw ValidationException::withMessages(['rooms' => 'Add at least one room.']);
        }
        $types = RoomType::where('branch_id', $branch->id)->whereIn('id', $requested->pluck('room_type_id'))->get()->keyBy('id');
        $available = $this->availability->forBranch($branch->id, $times['in'], $times['until'], $existing?->id)
            ->keyBy(fn ($row) => $row['room_type']->id);

        $rooms = [];
        $usedRooms = [];
        foreach ($requested->values() as $i => $r) {
            $type = $types->get((int) ($r['room_type_id'] ?? 0));
            if (! $type) {
                throw ValidationException::withMessages(["rooms.$i.room_type_id" => 'Choose a room type of this branch.']);
            }
            $adults = max(1, (int) ($r['adults'] ?? 1));
            $children = max(0, (int) ($r['children'] ?? 0));
            if ($adults + $children > $type->max_capacity) {
                throw ValidationException::withMessages(["rooms.$i.adults" => "{$type->name} takes at most {$type->max_capacity} guests."]);
            }
            $room = null;
            if (! empty($r['room_id'])) {
                $room = Room::where('branch_id', $branch->id)->where('room_type_id', $type->id)->find((int) $r['room_id']);
                if (! $room || in_array($room->id, $usedRooms, true) || ! $this->availability->roomIsFree($room, $times['in'], $times['until'], $existing?->id)) {
                    throw ValidationException::withMessages(["rooms.$i.room_id" => 'This room is not free for these dates.']);
                }
                $usedRooms[] = $room->id;
            }
            $price = $this->quote->price($hotel, $type, $stayType, $times['in'], $times['out'], $guestTypeId);
            $rooms[] = ['type' => $type, 'room' => $room, 'adults' => $adults, 'children' => $children, 'total' => $price['total'], 'lines' => $price['lines']];
        }

        // No overbooking (Q10): enough rooms of each type for the whole stay.
        foreach (collect($rooms)->groupBy(fn ($r) => $r['type']->id) as $typeId => $group) {
            $left = $available->get($typeId)['available'] ?? 0;
            if ($group->count() > $left) {
                $name = $group->first()['type']->name;
                throw ValidationException::withMessages(['rooms' => $left === 0 ? "No {$name} room is free for these dates." : "Only {$left} {$name} room(s) are free for these dates."]);
            }
        }

        $subtotal = Money::sum(array_column($rooms, 'total'));
        $discountAmount = Money::percentOf($subtotal, $discount);
        $net = Money::sub($subtotal, $discountAmount);
        $tax = $this->quote->addedTaxes($net);
        $total = Money::add($net, $tax);
        $first = array_map(fn ($r) => $r['lines'][0]['amount'] ?? '0.00', $rooms);

        return [
            'branch' => $branch,
            'guest' => $guest,
            'policy' => $policy,
            'types' => $types,
            'stay_type' => $stayType,
            'check_in_at_utc' => $times['in'],
            'check_out_at_utc' => $times['out'],
            'blocked_until_utc' => $times['until'],
            'check_in_at' => HotelClock::iso($times['in'], $hotel),
            'check_out_at' => HotelClock::iso($times['out'], $hotel),
            'adults' => array_sum(array_column($rooms, 'adults')),
            'children' => array_sum(array_column($rooms, 'children')),
            'currency' => $hotel->default_currency ?? 'ETB',
            'rooms' => array_map(fn ($r) => [
                'room_type_id' => $r['type']->id,
                'room_type' => $r['type']->name,
                'room_id' => $r['room']?->id,
                'room_number' => $r['room']?->number,
                'adults' => $r['adults'],
                'children' => $r['children'],
                'total' => $r['total'],
                'lines' => $r['lines'],
            ], $rooms),
            'subtotal' => $subtotal,
            'discount_percent' => $discount,
            'discount_amount' => $discountAmount,
            'tax_total' => $tax,
            'total' => $total,
            'deposit_required' => $this->quote->deposit($total, $first),
            'policy_name' => $policy?->name,
        ];
    }

    /** @param  array<string, mixed>  $plan */
    private function saveRooms(Reservation $reservation, array $plan): void
    {
        foreach ($plan['rooms'] as $r) {
            $rr = $reservation->rooms()->create([
                'room_type_id' => $r['room_type_id'],
                'room_id' => $r['room_id'],
                'check_in_at' => $plan['check_in_at_utc'],
                'check_out_at' => $plan['check_out_at_utc'],
                'blocked_until' => $plan['blocked_until_utc'],
                'adults' => $r['adults'],
                'children' => $r['children'],
                'total_amount' => $r['total'],
                'status' => $reservation->status,
            ]);
            foreach ($r['lines'] as $line) {
                $rr->rates()->create($line);
            }
        }
    }

    /** @param  array<string, mixed>  $data */
    private function policy(array $data, User $user, ?Reservation $existing): ?CancellationPolicy
    {
        $id = $data['cancellation_policy_id'] ?? null;
        $default = CancellationPolicy::where('is_default', true)->first() ?? CancellationPolicy::orderBy('id')->first();
        if (! $id) {
            return $existing?->cancellation_policy_id ? CancellationPolicy::find($existing->cancellation_policy_id) ?? $default : $default;
        }
        $policy = CancellationPolicy::find((int) $id);
        if (! $policy) {
            throw ValidationException::withMessages(['cancellation_policy_id' => 'Choose a cancellation policy.']);
        }
        $unchanged = $existing ? $policy->id === $existing->cancellation_policy_id : $policy->id === $default?->id;
        if (! $unchanged && ! $user->hasPermission('reservations.change_policy')) {
            throw ValidationException::withMessages(['cancellation_policy_id' => 'You may not change the cancellation policy.']);
        }

        return $policy;
    }

    /** Lock the room types being booked so two people cannot sell the last room at once. */
    private function lockTypes(array $data): void
    {
        $ids = collect($data['rooms'] ?? [])->pluck('room_type_id')->filter()->map(fn ($v) => (int) $v)->unique()->values();
        if ($ids->isNotEmpty()) {
            RoomType::whereIn('id', $ids)->lockForUpdate()->get(['id']);
        }
    }

    private function code(Branch $branch, Hotel $hotel): string
    {
        $year = HotelClock::now($hotel)->format('Y');
        $n = NumberSequence::next("reservation-{$year}");
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $branch->code) ?: 'RES');

        return sprintf('%s-%s-%05d', $prefix, substr($year, 2), $n);
    }

    /** Booking totals again from its rooms (after an extension or a move to another room type). */
    public function refreshTotals(Reservation $reservation): void
    {
        $subtotal = Money::sum($reservation->rooms()->pluck('total_amount')->map(fn ($v) => (string) $v)->all());
        $discount = Money::percentOf($subtotal, (string) $reservation->discount_percent);
        $net = Money::sub($subtotal, $discount);
        $tax = $this->quote->addedTaxes($net);
        $reservation->update(['subtotal' => $subtotal, 'discount_amount' => $discount, 'tax_total' => $tax, 'total' => Money::add($net, $tax)]);
    }

    public function refreshPaid(Reservation $reservation): void
    {
        $payments = Payment::where('reservation_id', $reservation->id)->get(['type', 'amount_in_base']);
        $in = Money::sum($payments->where('type', '!=', 'refund')->pluck('amount_in_base')->map(fn ($v) => (string) $v));
        $out = Money::sum($payments->where('type', 'refund')->pluck('amount_in_base')->map(fn ($v) => (string) $v));
        $reservation->update(['paid_total' => Money::sub($in, $out)]);
    }

    /** "Pay at check-out": the hotel allows it (Booking rules) and the person has the permission. */
    public function canPayLater(User $user): bool
    {
        return (bool) $this->settings->group(HotelSettings::BOOKING_DEFAULTS)['allow_pay_later'] && $user->hasPermission('payments.pay_later');
    }

    public function assertPayLater(User $user): void
    {
        if (! $this->canPayLater($user)) {
            throw ValidationException::withMessages(['pay_later' => 'Paying at check-out is not allowed for you. Ask your manager.']);
        }
    }

    /** A pending booking is confirmed without a deposit: the guest pays everything at check-out. */
    public function payLater(Reservation $reservation, User $user): Reservation
    {
        $this->assertPayLater($user);
        if ($reservation->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Only bookings waiting for a deposit can be changed to pay at check-out.']);
        }

        return DB::transaction(function () use ($reservation, $user) {
            $reservation->update(['status' => 'confirmed', 'expires_at' => null, 'pay_later_by' => $user->id, 'pay_later_at' => now()]);
            $reservation->rooms()->update(['status' => 'confirmed']);

            return $reservation->fresh();
        });
    }

    public function confirmIfPaid(Reservation $reservation): void
    {
        if ($reservation->status === 'pending' && Money::compare((string) $reservation->paid_total, (string) $reservation->deposit_required) >= 0) {
            $reservation->update(['status' => 'confirmed', 'expires_at' => null]);
            $reservation->rooms()->update(['status' => 'confirmed']);
        }
    }

    /** Sum of the first $n nights of every room (for "1 night" cancellation fees). */
    private function firstNights(Reservation $reservation, int $n): string
    {
        /** @var Collection<int, ReservationRoom> $rooms */
        $rooms = $reservation->rooms()->with('rates')->get();

        return Money::sum($rooms->flatMap(fn (ReservationRoom $r) => $r->rates->take(max(1, $n))->pluck('amount')->map(fn ($v) => (string) $v)));
    }
}
