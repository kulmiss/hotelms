<?php

namespace App\Services\Booking;

use App\Events\GuestCheckedOut;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Billing\InvoiceService;
use App\Services\GuestIdPhotos;
use App\Services\HotelSettings;
use App\Services\PriceCalculator;
use App\Support\HotelClock;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Front desk (workflows.md W3–W7): walk-in, check-in, check-out,
 * room move, extend stay and room status.
 */
class FrontDesk
{
    /** Minutes after the check-out time before a late check-out fee is suggested. */
    public const LATE_GRACE_MINUTES = 30;

    /** Hourly and day-use guests: minutes after the end time before extra hours are charged. */
    public const OVERSTAY_GRACE_MINUTES = 15;

    public function __construct(
        private ReservationService $reservations,
        private InvoiceService $invoices,
        private Availability $availability,
        private StayQuote $quote,
        private HotelSettings $settings,
        private PriceCalculator $prices,
    ) {}

    /**
     * Check in (W4). $data: rooms [{id, room_id}], waive_early_fee, payment {...}.
     *
     * @param  array<string, mixed>  $data
     */
    public function checkIn(Reservation $reservation, array $data, User $user): Reservation
    {
        if (! in_array($reservation->status, ['pending', 'confirmed'], true)) {
            throw ValidationException::withMessages(['status' => 'Only pending or confirmed bookings can be checked in.']);
        }
        $hotel = $user->hotel;
        $now = now();
        $localToday = HotelClock::now($hotel)->toDateString();
        $inDate = HotelClock::local($reservation->check_in_at, $hotel)->toDateString();
        $backfill = ! empty($data['backfill']); // demo history only (never reachable from the API)
        if (! $backfill && ($reservation->stay_type === 'nightly' ? $localToday < $inDate : $now->lt($reservation->check_in_at->copy()->subHour()))) {
            throw ValidationException::withMessages(['status' => 'This booking starts on '.$inDate.'. Change the dates to check in today.']);
        }
        // Until the check-out time (a guest arriving at 01:30 for last night's booking still checks in).
        if (! $backfill && $now->gte($reservation->check_out_at)) {
            throw ValidationException::withMessages(['status' => 'This stay has already ended. Change the dates first.']);
        }
        // The hotel requires a photo of the guest's ID (quick check-in adds it later).
        if (! $backfill && empty($data['skip_id_photo']) && app(GuestIdPhotos::class)->missingFor($reservation)) {
            throw ValidationException::withMessages(['id_photo' => "Take a photo of the guest's ID or passport before check-in."]);
        }
        $payment = ! empty($data['payment']['amount']) ? $data['payment'] : null;
        if ($payment && ! $user->hasPermission('payments.take')) {
            throw ValidationException::withMessages(['payment' => 'You may not take payments.']);
        }

        $waive = (bool) ($data['waive_early_fee'] ?? false);
        if ($waive && ! $user->hasPermission('fees.waive')) {
            throw ValidationException::withMessages(['waive_early_fee' => 'You may not waive fees.']);
        }

        return DB::transaction(function () use ($reservation, $data, $user, $now, $payment, $waive) {
            $chosen = collect($data['rooms'] ?? [])->keyBy(fn ($r) => (int) $r['id']);
            $rooms = $reservation->rooms()->with(['rates', 'roomType'])->lockForUpdate()->get();
            $used = [];
            foreach ($rooms as $i => $rr) {
                $roomId = (int) ($chosen->get($rr->id)['room_id'] ?? $rr->room_id ?? 0);
                $room = Room::where('branch_id', $reservation->branch_id)->lockForUpdate()->find($roomId);
                if (! $room) {
                    throw ValidationException::withMessages(["rooms.$i.room_id" => 'Choose a room for every booked room.']);
                }
                if ($room->status !== 'available') {
                    throw ValidationException::withMessages(["rooms.$i.room_id" => "Room {$room->number} is {$room->status}; only clean, available rooms can be given."]);
                }
                $from = $now->lt($rr->check_in_at) ? $now : $rr->check_in_at;
                if (in_array($room->id, $used, true) || ! $this->availability->roomIsFree($room, $from, $rr->blocked_until, $reservation->id)) {
                    throw ValidationException::withMessages(["rooms.$i.room_id" => "Room {$room->number} is booked by someone else during this stay."]);
                }
                if ($room->room_type_id !== $rr->room_type_id) {
                    throw ValidationException::withMessages(["rooms.$i.room_id" => "Room {$room->number} is another room type. Give a room of the booked type (you can move the guest after check-in)."]);
                }
                $used[] = $room->id;
                $rr->update(['room_id' => $room->id]);
            }
            // Early arrival: the extra hours before the check-in time must not take a room another booking counts on.
            foreach ($rooms->filter(fn ($rr) => $now->lt($rr->check_in_at))->groupBy('room_type_id') as $group) {
                $first = $group->first();
                if ($this->availability->availableCount($first->roomType, $now, $first->check_in_at, $reservation->id) < $group->count()) {
                    throw ValidationException::withMessages(['rooms' => 'Too early: these rooms are still promised to other guests until the check-in time.']);
                }
            }

            // Deposit before the guest gets the key (pending bookings).
            if ($payment) {
                $this->reservations->recordPayment($reservation, $payment + ['type' => 'deposit'], $user);
                $reservation->refresh();
            }
            if ($reservation->status === 'pending' && Money::compare((string) $reservation->paid_total, (string) $reservation->deposit_required) < 0) {
                throw ValidationException::withMessages(['payment' => 'Take the deposit of '.$reservation->deposit_required.' '.$reservation->currency.' first.']);
            }

            foreach ($reservation->rooms()->with(['room', 'rates'])->get() as $rr) {
                $rr->update([
                    'status' => 'checked_in',
                    'actual_check_in_at' => $now,
                    'check_in_at' => $now->lt($rr->check_in_at) ? $now : $rr->check_in_at,
                ]);
                $rr->room->update(['status' => 'occupied']);
            }
            $reservation->update(['status' => 'checked_in', 'expires_at' => null, 'checked_in_by' => $user->id]);

            $invoice = $this->invoices->openFor($reservation->fresh(), $user);
            $early = $this->earlyFee($reservation, $now);
            if ($early) {
                foreach ($reservation->rooms()->with(['room', 'rates'])->get() as $rr) {
                    $this->invoices->line($invoice, [
                        'type' => 'early_check_in',
                        'description' => 'Early check-in · Room '.$rr->room?->number,
                        'unit_price' => $this->fee($early, (string) ($rr->rates->first()?->amount ?? '0')),
                        'is_waived' => $waive,
                        'reservation_room_id' => $rr->id,
                        'service_date' => HotelClock::now($user->hotel)->toDateString(),
                    ], $user);
                }
                $this->invoices->recalc($invoice);
            }

            return $reservation->fresh();
        });
    }

    /**
     * Walk-in (W3): book and check in at once; payment up front as the hotel set (Q6).
     *
     * @param  array<string, mixed>  $data
     */
    public function walkIn(array $data, User $user): Reservation
    {
        $rules = $this->settings->group(HotelSettings::BOOKING_DEFAULTS);
        $data['walk_in'] = true;
        $data['source'] = 'walk_in';
        $data['check_in'] = HotelClock::now($user->hotel)->format('Y-m-d\TH:i');
        if (($data['stay_type'] ?? 'nightly') === 'nightly') {
            $data['check_in'] = $this->nightDate($user->hotel); // after midnight, before the cut-off: last night
        }
        foreach ($data['rooms'] ?? [] as $i => $r) {
            if (empty($r['room_id'])) {
                throw ValidationException::withMessages(["rooms.$i.room_id" => 'Choose the room.']);
            }
        }

        return DB::transaction(function () use ($data, $user, $rules) {
            $preview = $this->reservations->preview($data, $user);
            $payLater = ! empty($data['pay_later']);
            if ($payLater) {
                $this->reservations->assertPayLater($user);
            }
            $required = $payLater ? '0.00' : ($rules['walk_in_payment'] === 'deposit' ? $preview['deposit_required'] : $preview['total']);
            $paid = (string) ($data['payment']['amount'] ?? '0');
            if (Money::isPositive($required) && empty($data['payment']['payment_method_id'])) {
                throw ValidationException::withMessages(['payment.amount' => 'Take '.$required.' '.$preview['currency'].' before check-in.']);
            }

            $reservation = $this->reservations->create($data, $user);
            if (Money::isPositive($paid)) {
                $this->reservations->recordPayment($reservation, $data['payment'] + ['type' => $rules['walk_in_payment'] === 'deposit' ? 'deposit' : 'payment'], $user);
                $reservation->refresh();
            }
            if (Money::compare((string) $reservation->paid_total, $required) < 0) {
                throw ValidationException::withMessages(['payment.amount' => 'Take at least '.$required.' '.$reservation->currency.' before check-in.']);
            }
            if ($reservation->status === 'pending') { // fully covered by the up-front payment rule
                $reservation->update(['status' => 'confirmed', 'expires_at' => null]);
            }

            return $this->checkIn($reservation->fresh(), ['skip_id_photo' => ! empty($data['quick'])], $user);
        });
    }

    /**
     * The date of the night a walk-in arriving now pays for: before the hotel's cut-off (default 06:00)
     * it is still last night, so a guest arriving at 00:14 on the 7th leaves on the 7th.
     */
    public function nightDate(Hotel $hotel): string
    {
        $now = HotelClock::now($hotel);
        $cutoff = (string) ($this->settings->group(HotelSettings::BOOKING_DEFAULTS)['night_cutoff'] ?: '00:00');

        return ($now->format('H:i') < $cutoff ? $now->copy()->subDay() : $now)->toDateString();
    }

    /**
     * Check out (W6). $data: waive_late_fee, waive_early_fee, payment {...}, approve_balance.
     *
     * @param  array<string, mixed>  $data
     */
    public function checkOut(Reservation $reservation, array $data, User $user): Reservation
    {
        if ($reservation->status !== 'checked_in') {
            throw ValidationException::withMessages(['status' => 'Only checked-in guests can be checked out.']);
        }
        $waive = (bool) ($data['waive_late_fee'] ?? false);
        $waiveEarly = (bool) ($data['waive_early_fee'] ?? false);
        if (($waive || $waiveEarly) && ! $user->hasPermission('fees.waive')) {
            throw ValidationException::withMessages([$waive ? 'waive_late_fee' : 'waive_early_fee' => 'You may not waive fees.']);
        }
        $now = now();

        return DB::transaction(function () use ($reservation, $data, $user, $now, $waive, $waiveEarly) {
            $invoice = $this->invoices->openFor($reservation, $user);

            // Leaving before the booked date: only the nights stayed are charged, plus the hotel's fee.
            if (empty($data['backfill']) && ($early = $this->earlyDeparture($reservation, $now))) {
                $this->shortenStay($reservation, $invoice, $early, $waiveEarly, $now, $user);
                $invoice = $this->invoices->recalc($invoice);
            }

            // Hourly and day-use guests who stay longer pay the extra hours (owner, 2026-11-07), once.
            $over = empty($data['backfill']) ? $this->overstay($reservation, $now) : [];
            if ($over && ! $invoice->items()->where('type', 'late_check_out')->exists()) {
                foreach ($over as $line) {
                    $this->invoices->line($invoice, $line + ['type' => 'late_check_out', 'is_waived' => $waive, 'service_date' => HotelClock::now($user->hotel)->toDateString()], $user);
                }
                $invoice = $this->invoices->recalc($invoice);
            }

            // Late check-out fee, once.
            $late = empty($data['backfill']) ? $this->lateFee($reservation, $now) : null;
            if ($late && ! $invoice->items()->where('type', 'late_check_out')->exists()) {
                foreach ($reservation->rooms()->with(['room', 'rates'])->get() as $rr) {
                    $this->invoices->line($invoice, [
                        'type' => 'late_check_out',
                        'description' => 'Late check-out · Room '.$rr->room?->number,
                        'unit_price' => $this->fee($late, (string) ($rr->rates->last()?->amount ?? '0')),
                        'is_waived' => $waive,
                        'reservation_room_id' => $rr->id,
                        'service_date' => HotelClock::now($user->hotel)->toDateString(),
                    ], $user);
                }
                $invoice = $this->invoices->recalc($invoice);
            }

            if (! empty($data['payment']['amount'])) {
                if (! $user->hasPermission('payments.take')) {
                    throw ValidationException::withMessages(['payment' => 'You may not take payments.']);
                }
                $this->reservations->payAcrossBills($reservation, $data['payment'] + ['type' => 'payment'], $user);
            }

            // Money still owed needs a manager (Q8).
            foreach ($reservation->invoices()->where('status', '!=', 'void')->get() as $inv) {
                $inv = $this->invoices->recalc($inv);
                if (Money::isPositive((string) $inv->balance) && ! $inv->outstanding_approved_at) {
                    if (! empty($data['approve_balance']) && $user->hasPermission('checkout.approve_balance')) {
                        $this->invoices->approveBalance($inv, $user);
                    } else {
                        throw ValidationException::withMessages(['balance' => 'The guest still owes '.$inv->balance.' '.$inv->currency.'. Take the payment, or a manager must approve the unpaid balance.']);
                    }
                }
            }

            foreach ($reservation->rooms()->with('room')->get() as $rr) {
                $rr->update([
                    'status' => 'checked_out',
                    'actual_check_out_at' => $now,
                    'blocked_until' => $now->lt($rr->blocked_until) ? $now : $rr->blocked_until,
                ]);
                $rr->room?->update(['status' => 'dirty']);
            }
            $reservation->update(['status' => 'checked_out', 'checked_out_by' => $user->id]);
            event(new GuestCheckedOut($reservation->fresh()));

            return $reservation->fresh();
        });
    }

    /**
     * A guest leaving before the booked check-out date (owner, 2026-11-07): the nights not used are
     * taken off the bill and the hotel's early check-out fee is added (per room; reception may waive it).
     * The first night is always charged. Null when the guest is not leaving early.
     *
     * @return array{from: string, nights: int, refund: string, fee: string, rooms: list<array{rr: ReservationRoom, fee: string, from: string}>}|null
     */
    public function earlyDeparture(Reservation $reservation, Carbon $at): ?array
    {
        if ($reservation->stay_type !== 'nightly' || $reservation->status !== 'checked_in') {
            return null;
        }
        $hotel = $reservation->hotel;
        $today = HotelClock::local($at, $hotel)->toDateString();
        $rules = $this->settings->group(HotelSettings::BOOKING_DEFAULTS);
        $rule = ['type' => (string) $rules['early_check_out_fee_type'], 'value' => (string) $rules['early_check_out_fee_value']];

        $rooms = [];
        $nights = 0;
        $refund = '0.00';
        $fees = '0.00';
        $from = null;
        foreach ($reservation->rooms()->with(['rates', 'room', 'roomType'])->get() as $rr) {
            // The first night is always paid (it may be last night, for a walk-in after midnight).
            $first = $rr->rates->where('label', 'Night')->min(fn ($rate) => $rate->date->toDateString());
            $cut = $first ? max($today, Carbon::parse($first)->addDay()->toDateString()) : $today;
            $from = $from === null ? $cut : min($from, $cut);
            $unused = $rr->rates->filter(fn ($rate) => $rate->label === 'Night' && $rate->date->toDateString() >= $cut);
            if ($unused->isEmpty()) {
                continue;
            }
            $fee = Money::isPositive($rule['value']) ? $this->fee($rule, (string) $unused->first()->amount) : '0.00';
            $rooms[] = ['rr' => $rr, 'fee' => $fee, 'from' => $cut];
            $nights = max($nights, $unused->count());
            $refund = Money::add($refund, Money::sum($unused->pluck('amount')->map(fn ($v) => (string) $v)));
            $fees = Money::add($fees, $fee);
        }

        return $rooms === [] ? null : ['from' => (string) $from, 'nights' => $nights, 'refund' => $refund, 'fee' => $fees, 'rooms' => $rooms];
    }

    /**
     * What leaving early now would do to the bill (for the check-out screen): the balance after the
     * unused nights are taken off, with the fee and without it. Worked out for real, then undone.
     *
     * @return array{from: string, nights: int, fee: string, balance: string, balance_without_fee: string}|null
     */
    public function earlyDeparturePreview(Reservation $reservation, User $user): ?array
    {
        $early = $this->earlyDeparture($reservation, now());
        if (! $early) {
            return null;
        }
        DB::beginTransaction();
        try {
            $invoice = $this->invoices->openFor($reservation, $user);
            $this->shortenStay($reservation, $invoice, $early, false, now(), $user);
            $this->invoices->recalc($invoice);
            $balance = static::balance($reservation);
            $fee = Money::sum($invoice->items()->with('taxes')->where('type', 'early_check_out')->get()
                ->map(fn ($i) => Money::add((string) $i->amount, Money::sum($i->taxes->where('is_inclusive', false)->pluck('amount')->map(fn ($v) => (string) $v)))));
        } finally {
            DB::rollBack();
            $reservation->refresh();
        }

        return ['from' => $early['from'], 'nights' => $early['nights'], 'fee' => $fee, 'balance' => $balance, 'balance_without_fee' => Money::sub($balance, $fee)];
    }

    /** @param  array{from: string, rooms: list<array{rr: ReservationRoom, fee: string, from: string}>}  $early */
    private function shortenStay(Reservation $reservation, Invoice $invoice, array $early, bool $waive, Carbon $now, User $user): void
    {
        foreach ($early['rooms'] as ['rr' => $rr, 'fee' => $fee, 'from' => $from]) {
            $rr->rates()->where('label', 'Night')->where('date', '>=', $from)->delete();
            $invoice->items()->where('reservation_room_id', $rr->id)->where('type', 'room')->where('service_date', '>=', $from)->get()->each->delete();
            if (Money::isPositive($fee)) {
                $this->invoices->line($invoice, [
                    'type' => 'early_check_out',
                    'description' => 'Early check-out · Room '.$rr->room?->number,
                    'unit_price' => $fee,
                    'is_waived' => $waive,
                    'reservation_room_id' => $rr->id,
                    'service_date' => HotelClock::now($user->hotel)->toDateString(),
                ], $user);
            }
            $rr->update(['check_out_at' => $now, 'total_amount' => Money::sum($rr->rates()->pluck('amount')->map(fn ($v) => (string) $v))]);
        }
        $reservation->update(['check_out_at' => $now]);
        $this->reservations->refreshTotals($reservation);
        // The booking's discount (a percent) follows the shorter stay.
        $reservation->refresh();
        $booking = $invoice->items()->where('type', 'discount')->whereNull('reservation_room_id')->where('discount_percent', $reservation->discount_percent)->orderBy('id')->first();
        if ($booking && Money::isPositive((string) $reservation->discount_percent)) {
            $line = ['description' => $booking->description, 'discount_percent' => (string) $booking->discount_percent, 'service_date' => $booking->service_date?->toDateString()];
            $booking->delete(); // a new line, so its taxes are worked out again
            $this->invoices->line($invoice, $line + ['type' => 'discount', 'unit_price' => Money::sub('0', (string) $reservation->discount_amount)], $user);
        }
        app(ActivityLogger::class)->log('front.early_check_out', "{$reservation->code}: left early, nights from {$early['from']} taken off the bill", $reservation);
    }

    /** Room move (W5): old room → dirty, new room → occupied; nights from today follow the new room type. */
    public function moveRoom(Reservation $reservation, ReservationRoom $rr, int $roomId, User $user): Reservation
    {
        if (! in_array($reservation->status, ['pending', 'confirmed', 'checked_in'], true)) {
            throw ValidationException::withMessages(['status' => 'This booking cannot change rooms.']);
        }
        $room = Room::where('branch_id', $reservation->branch_id)->find($roomId);
        if (! $room || $room->id === $rr->room_id) {
            throw ValidationException::withMessages(['room_id' => 'Choose another room.']);
        }
        $inHouse = $reservation->status === 'checked_in';
        $from = $inHouse ? now() : $rr->check_in_at;
        if ($inHouse && $room->status !== 'available') {
            throw ValidationException::withMessages(['room_id' => "Room {$room->number} is {$room->status}."]);
        }
        if (! $this->availability->roomIsFree($room, $from, $rr->blocked_until, $reservation->id)) {
            throw ValidationException::withMessages(['room_id' => "Room {$room->number} is not free for the rest of the stay."]);
        }
        if (! $inHouse && $room->room_type_id !== $rr->room_type_id) {
            throw ValidationException::withMessages(['room_id' => 'Before arrival, choose a room of the booked type (or change the booking).']);
        }

        return DB::transaction(function () use ($reservation, $rr, $room, $from, $inHouse, $user) {
            // Again under a lock, so two moves into the same room cannot both win.
            $room = Room::whereKey($room->id)->lockForUpdate()->first();
            if (! $this->availability->roomIsFree($room, $from, $rr->blocked_until, $reservation->id)) {
                throw ValidationException::withMessages(['room_id' => "Room {$room->number} is not free for the rest of the stay."]);
            }
            $old = $rr->room;
            $typeChanged = $room->room_type_id !== $rr->room_type_id;
            // An upgrade uses a room of the other type: bookings already counting on that type must still fit.
            if ($typeChanged) {
                RoomType::whereKey($room->room_type_id)->lockForUpdate()->first();
                if ($this->availability->availableCount($room->roomType, $from, $rr->blocked_until, $reservation->id) < 1) {
                    throw ValidationException::withMessages(['room_id' => "All {$room->roomType->name} rooms are promised to other bookings during this stay."]);
                }
            }
            $rr->update(['room_id' => $room->id, 'room_type_id' => $room->room_type_id]);

            if ($inHouse) {
                $old?->update(['status' => 'dirty']);
                $room->update(['status' => 'occupied']);
                if ($typeChanged && $reservation->stay_type === 'nightly') {
                    $this->repriceFromToday($reservation, $rr->fresh(['roomType']), $user);
                }
                $invoice = $this->invoices->openFor($reservation, $user);
                $invoice->items()->where('reservation_room_id', $rr->id)->where('type', 'room')
                    ->where('service_date', '>=', HotelClock::now($user->hotel)->toDateString())
                    ->get()->each(fn ($item) => $item->update(['description' => preg_replace('/^Room \S+/', 'Room '.$room->number, $item->description)]));
            }

            return $reservation->fresh();
        });
    }

    /**
     * Extend a stay in the same room (W5). Nightly: a later check-out date; hourly: more hours.
     *
     * @param  array<string, mixed>  $data
     */
    public function extend(Reservation $reservation, array $data, User $user): Reservation
    {
        if ($reservation->status !== 'checked_in') {
            throw ValidationException::withMessages(['status' => 'Change the booking instead (it has not started).']);
        }
        $hotel = $user->hotel;
        $branch = $reservation->branch;
        $rules = $this->settings->group(HotelSettings::BOOKING_DEFAULTS);

        if ($reservation->stay_type === 'nightly') {
            $oldOut = HotelClock::local($reservation->check_out_at, $hotel)->startOfDay();
            $newOut = Carbon::parse((string) ($data['check_out'] ?? ''), HotelClock::zone($hotel))->startOfDay();
            if ($newOut->lte($oldOut)) {
                throw ValidationException::withMessages(['check_out' => 'Choose a later check-out date.']);
            }
            $inDay = HotelClock::local($reservation->check_in_at, $hotel)->startOfDay();
            if ($inDay->diffInDays($newOut) > 90) {
                throw ValidationException::withMessages(['check_out' => 'A stay can be at most 90 nights. Start a new booking for longer stays.']);
            }
            $out = $newOut->copy()->setTimeFromTimeString((string) ($branch->check_out_time ?: '12:00'))->utc();
            $until = $out->copy();
        } else {
            $hours = (int) ($data['hours'] ?? 0);
            if ($hours < 1) {
                throw ValidationException::withMessages(['hours' => 'Add at least one hour.']);
            }
            $out = $reservation->check_out_at->copy()->addHours($hours);
            $until = $out->copy()->addMinutes((int) $rules['hourly_cleaning_minutes']);
        }

        return DB::transaction(function () use ($reservation, $user, $out, $until) {
            $invoice = $this->invoices->openFor($reservation, $user);
            foreach ($reservation->rooms()->with(['room', 'roomType'])->lockForUpdate()->get() as $rr) {
                if (! $this->availability->roomIsFree($rr->room, $rr->blocked_until, $until, $reservation->id)) {
                    throw ValidationException::withMessages(['check_out' => "Room {$rr->room->number} is booked after this stay. Move the guest to another room first."]);
                }
                $lines = $this->extraLines($reservation, $rr, $out);
                foreach ($lines as $line) {
                    $rr->rates()->create($line);
                    $this->invoices->line($invoice, [
                        'type' => 'room', 'description' => 'Room '.$rr->room->number.' · '.$rr->roomType->name.' · '.$line['label'],
                        'unit_price' => $line['amount'], 'service_date' => $line['date'], 'reservation_room_id' => $rr->id,
                    ], $user);
                }
                $rr->update(['check_out_at' => $out, 'blocked_until' => $until, 'total_amount' => Money::add((string) $rr->total_amount, Money::sum(array_column($lines, 'amount')))]);
            }
            $reservation->update(['check_out_at' => $out]);
            $this->reservations->refreshTotals($reservation);
            $this->invoices->recalc($invoice);

            return $reservation->fresh();
        });
    }

    /** Room status by hand (W7). Occupied comes only from check-in. */
    public function setRoomStatus(Room $room, string $status, User $user): Room
    {
        $manager = in_array($status, ['maintenance', 'out_of_order'], true) || in_array($room->status, ['maintenance', 'out_of_order'], true);
        if ($manager && ! $user->hasPermission('rooms.maintenance')) {
            throw ValidationException::withMessages(['status' => 'Only a manager can set or clear maintenance.']);
        }
        if (! $manager && ! $user->hasPermission('rooms.change_status')) {
            throw ValidationException::withMessages(['status' => 'You may not change room status.']);
        }
        if ($status === 'occupied' || $room->status === 'occupied') {
            throw ValidationException::withMessages(['status' => 'Occupied rooms change by check-in and check-out.']);
        }
        $room->update(['status' => $status]);

        return $room->fresh();
    }

    /** @return array{type: string, value: string}|null early check-in fee rule, when the guest arrives before the check-in time */
    public function earlyFee(Reservation $reservation, Carbon $at): ?array
    {
        if ($reservation->stay_type !== 'nightly' || $at->gte($reservation->check_in_at)) {
            return null;
        }
        $rules = $this->settings->group(HotelSettings::BOOKING_DEFAULTS);

        return Money::isPositive((string) $rules['early_check_in_fee_value']) ? ['type' => $rules['early_check_in_fee_type'], 'value' => (string) $rules['early_check_in_fee_value']] : null;
    }

    /** @return array{type: string, value: string}|null */
    public function lateFee(Reservation $reservation, Carbon $at): ?array
    {
        if ($reservation->stay_type !== 'nightly' || $at->lte($reservation->check_out_at->copy()->addMinutes(self::LATE_GRACE_MINUTES))) {
            return null;
        }
        $rules = $this->settings->group(HotelSettings::BOOKING_DEFAULTS);

        return Money::isPositive((string) $rules['late_check_out_fee_value']) ? ['type' => $rules['late_check_out_fee_type'], 'value' => (string) $rules['late_check_out_fee_value']] : null;
    }

    /**
     * Hourly and day-use stays past their end time (after 15 minutes): each started hour at the room's
     * hourly price; a room type without one uses the hotel's late check-out fee on the stay's price.
     *
     * @return list<array{description: string, unit_price: string, quantity: string, reservation_room_id: int}>
     */
    public function overstay(Reservation $reservation, Carbon $at): array
    {
        if ($reservation->stay_type === 'nightly' || $reservation->status !== 'checked_in' || $at->lte($reservation->check_out_at->copy()->addMinutes(self::OVERSTAY_GRACE_MINUTES))) {
            return [];
        }
        $hours = (int) ceil($reservation->check_out_at->diffInMinutes($at) / 60);
        $rules = $this->settings->group(HotelSettings::BOOKING_DEFAULTS);
        $lines = [];
        foreach ($reservation->rooms()->with(['room', 'roomType', 'rates'])->get() as $rr) {
            $hourly = $rr->roomType?->hourly_price;
            $lines[] = $hourly !== null
                ? ['description' => "Extra {$hours} h · Room ".$rr->room?->number, 'unit_price' => (string) $hourly, 'quantity' => (string) $hours, 'reservation_room_id' => $rr->id]
                : ['description' => 'Late check-out · Room '.$rr->room?->number, 'unit_price' => $this->fee(['type' => (string) $rules['late_check_out_fee_type'], 'value' => (string) $rules['late_check_out_fee_value']], (string) ($rr->rates->first()?->amount ?? '0')), 'quantity' => '1', 'reservation_room_id' => $rr->id];
        }

        return array_values(array_filter($lines, fn ($l) => Money::isPositive(Money::mul($l['unit_price'], $l['quantity']))));
    }

    /** @param  array{type: string, value: string}  $rule */
    private function fee(array $rule, string $night): string
    {
        return $rule['type'] === 'fixed' ? Money::round($rule['value']) : Money::percentOf($night, $rule['value']);
    }

    /** @return list<array{date: string, label: string, amount: string, rate_rule_id: ?int, rule_name: ?string}> */
    private function extraLines(Reservation $reservation, ReservationRoom $rr, Carbon $newOut): array
    {
        $hotel = $reservation->hotel;
        $guestTypeId = $reservation->guest?->guest_type_id;
        if ($reservation->stay_type === 'nightly') {
            $stay = $this->prices->stay($rr->roomType, HotelClock::local($rr->check_out_at, $hotel)->startOfDay(), HotelClock::local($newOut, $hotel)->startOfDay(), $guestTypeId);

            return array_map(fn ($n) => ['date' => $n['date'], 'label' => 'Night', 'amount' => $n['amount'], 'rate_rule_id' => $n['rule']['id'] ?? null, 'rule_name' => $n['rule']['name'] ?? null], $stay['nights']);
        }
        $hours = (int) ceil($rr->check_out_at->diffInMinutes($newOut) / 60);
        $unit = (string) ($rr->roomType->hourly_price ?? $rr->roomType->day_use_price ?? '0');

        return [['date' => HotelClock::now($hotel)->toDateString(), 'label' => "+{$hours} hours", 'amount' => Money::mul($unit, (string) $hours), 'rate_rule_id' => null, 'rule_name' => null]];
    }

    /** After a move to another room type: nights from today are charged at the new type's price. */
    private function repriceFromToday(Reservation $reservation, ReservationRoom $rr, User $user): void
    {
        $hotel = $reservation->hotel;
        $today = HotelClock::now($hotel)->startOfDay();
        $out = HotelClock::local($rr->check_out_at, $hotel)->startOfDay();
        if ($today->gte($out)) {
            return;
        }
        $invoice = $this->invoices->openFor($reservation, $user);
        $rr->rates()->where('date', '>=', $today->toDateString())->delete();
        $invoice->items()->where('reservation_room_id', $rr->id)->where('type', 'room')->where('service_date', '>=', $today->toDateString())->get()->each->delete();

        $stay = $this->prices->stay($rr->roomType, $today, $out, $reservation->guest?->guest_type_id);
        foreach ($stay['nights'] as $n) {
            $rr->rates()->create(['date' => $n['date'], 'label' => 'Night', 'amount' => $n['amount'], 'rate_rule_id' => $n['rule']['id'] ?? null, 'rule_name' => $n['rule']['name'] ?? null]);
            $this->invoices->line($invoice, [
                'type' => 'room', 'description' => 'Room '.$rr->room?->number.' · '.$rr->roomType->name.' · Night',
                'unit_price' => $n['amount'], 'service_date' => $n['date'], 'reservation_room_id' => $rr->id,
            ], $user);
        }
        $rr->update(['total_amount' => Money::sum($rr->rates()->pluck('amount')->map(fn ($v) => (string) $v))]);
        $this->reservations->refreshTotals($reservation);
        $this->invoices->recalc($invoice);
    }

    /** Balance of a reservation's bills (for the board). */
    public static function balance(Reservation $reservation): string
    {
        return Money::sum(Invoice::where('reservation_id', $reservation->id)->where('status', '!=', 'void')->pluck('balance')->map(fn ($v) => (string) $v));
    }
}
