<?php

namespace App\Http\Controllers\Api\Front;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ReservationPresenter;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Invoice;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\TaxCalculator;
use App\Services\Booking\Availability;
use App\Services\Booking\FrontDesk;
use App\Services\GuestIdPhotos;
use App\Services\HotelSettings;
use App\Support\HotelClock;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Room board, today's lists, walk-in, check-in / check-out, room move, extend (workflows.md W3–W7). */
class FrontDeskController extends Controller
{
    public function __construct(private FrontDesk $desk, private Availability $availability) {}

    /** Every room with its status and who is in it (polled by the screen). */
    public function board(Request $request): JsonResponse
    {
        $user = $request->user();
        $hotel = $user->hotel;
        $branch = $this->branch($request);
        $day = $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'after:-3 years', 'before:+3 years']])['date'] ?? null;
        if ($day && $day !== HotelClock::now($hotel)->toDateString()) {
            return $this->boardOn($branch, $day, $hotel);
        }
        $todayEnd = HotelClock::now($hotel)->endOfDay()->utc();

        $stays = ReservationRoom::with(['reservation.guest:id,first_name,last_name'])->whereNotNull('room_id')
            ->whereHas('reservation', fn ($q) => $q->where('branch_id', $branch->id))
            ->where(fn ($q) => $q->where('status', 'checked_in')
                ->orWhere(fn ($w) => $w->whereIn('status', ['pending', 'confirmed'])->where('check_in_at', '<=', $todayEnd)))
            ->get();
        // The next booking of each room after today (so reception sees a room is taken from a future date).
        // (Two small queries: the first future date per room, then only those bookings; the board is polled every 30 s.)
        $roomIds = Room::where('branch_id', $branch->id)->pluck('id');
        $firsts = ReservationRoom::whereIn('room_id', $roomIds)->whereIn('status', ['pending', 'confirmed'])->where('check_in_at', '>', $todayEnd)
            ->groupBy('room_id')->selectRaw('room_id, MIN(check_in_at) as first_in')->pluck('first_in', 'room_id');
        $later = $firsts->isEmpty() ? collect() : ReservationRoom::with(['reservation.guest:id,first_name,last_name'])->whereIn('status', ['pending', 'confirmed'])
            ->where(fn ($q) => $firsts->each(fn ($at, $room) => $q->orWhere(fn ($w) => $w->where('room_id', $room)->where('check_in_at', $at))))
            ->get()->unique('room_id')->keyBy('room_id');
        $balances = Invoice::whereIn('reservation_id', $stays->pluck('reservation_id'))->where('status', '!=', 'void')
            ->selectRaw('reservation_id, sum(balance) as b')->groupBy('reservation_id')->pluck('b', 'reservation_id');

        $rooms = Room::with('roomType:id,name')->where('branch_id', $branch->id)->orderBy('floor')->orderByRaw('LENGTH(number)')->orderBy('number')->get()
            ->map(function (Room $room) use ($stays, $balances, $hotel, $later) {
                $in = $stays->first(fn ($s) => $s->room_id === $room->id && $s->status === 'checked_in');
                $next = $stays->first(fn ($s) => $s->room_id === $room->id && $s->status !== 'checked_in');
                $info = fn (?ReservationRoom $s) => $s ? [
                    'reservation_id' => $s->reservation_id, 'code' => $s->reservation?->code, 'guest' => $s->reservation?->guest?->full_name,
                    'check_in_at' => HotelClock::iso($s->check_in_at, $hotel), 'check_out_at' => HotelClock::iso($s->check_out_at, $hotel),
                    'balance' => (string) ($balances[$s->reservation_id] ?? '0.00'), 'overdue' => $s->status === 'checked_in' && now()->gt($s->check_out_at),
                    'open_stay' => (bool) $s->reservation?->open_stay, 'stay_type' => $s->reservation?->stay_type,
                ] : null;

                return [
                    'id' => $room->id, 'number' => $room->number, 'floor' => $room->floor, 'status' => $room->status,
                    'room_type_id' => $room->room_type_id, 'room_type' => $room->roomType?->name, 'notes' => $room->notes,
                    'stay' => $info($in), 'arriving' => $info($next), 'next_booking' => $info($later[$room->id] ?? null),
                ];
            });

        $counts = $rooms->countBy('status');

        return response()->json(['data' => $rooms->values(), 'counts' => $counts, 'refreshed_at' => HotelClock::iso(now(), $hotel)]);
    }

    /**
     * The rooms on another day (owner, 2026-11-07): who stays that night (booked, staying or already left),
     * free rooms, and rooms out of order. Read only; today's board is the live one.
     */
    private function boardOn(Branch $branch, string $day, Hotel $hotel): JsonResponse
    {
        $night = HotelClock::parse($day, $hotel)->addDay()->subSecond(); // the night of that day (last second of the day)
        $stays = ReservationRoom::with(['reservation.guest:id,first_name,last_name'])->whereNotNull('room_id')
            ->whereHas('reservation', fn ($q) => $q->where('branch_id', $branch->id))
            ->whereIn('status', ['pending', 'confirmed', 'checked_in', 'checked_out'])
            ->where('check_in_at', '<=', $night)->where('check_out_at', '>', $night)
            ->get()->keyBy('room_id');
        $rooms = Room::with('roomType:id,name')->where('branch_id', $branch->id)->orderBy('floor')->orderByRaw('LENGTH(number)')->orderBy('number')->get()
            ->map(function (Room $room) use ($stays, $hotel) {
                $s = $stays->get($room->id);
                $status = $s ? 'occupied' : (in_array($room->status, ['maintenance', 'out_of_order'], true) ? $room->status : 'available');

                return [
                    'id' => $room->id, 'number' => $room->number, 'floor' => $room->floor, 'status' => $status,
                    'room_type_id' => $room->room_type_id, 'room_type' => $room->roomType?->name, 'notes' => $room->notes,
                    'stay' => $s ? [
                        'reservation_id' => $s->reservation_id, 'code' => $s->reservation?->code, 'guest' => $s->reservation?->guest?->full_name,
                        'check_in_at' => HotelClock::iso($s->check_in_at, $hotel), 'check_out_at' => HotelClock::iso($s->check_out_at, $hotel),
                        'balance' => '0.00', 'overdue' => false, 'status' => $s->status, 'stay_type' => $s->reservation?->stay_type,
                    ] : null,
                    'arriving' => null, 'next_booking' => null,
                ];
            });

        return response()->json(['data' => $rooms->values(), 'counts' => $rooms->countBy('status'), 'date' => $day, 'refreshed_at' => HotelClock::iso(now(), $hotel)]);
    }

    /** Arrivals today (late ones flagged), departures today (overdue flagged), guests in house. */
    public function today(Request $request): JsonResponse
    {
        $user = $request->user();
        $hotel = $user->hotel;
        $branch = $this->branch($request);
        $start = HotelClock::now($hotel)->startOfDay()->utc();
        $end = HotelClock::now($hotel)->endOfDay()->utc();
        $base = fn () => Reservation::with(['guest:id,first_name,last_name,phone', 'branch:id,name', 'rooms.roomType:id,name', 'rooms.room:id,number'])->where('branch_id', $branch->id);

        $arrivals = $base()->whereIn('status', ['pending', 'confirmed'])->where('check_in_at', '<=', $end)->where('check_out_at', '>', $start)->orderBy('check_in_at')->get();
        $inHouse = $base()->where('status', 'checked_in')->orderBy('check_out_at')->get();
        // Open stays (Q16) are listed too: reception asks them each day "still staying?".
        $departures = $inHouse->filter(fn (Reservation $r) => $r->check_out_at->lte($end) || $r->open_stay)->values();
        // Every balance in one question (not one per booking).
        $balances = Invoice::whereIn('reservation_id', $arrivals->pluck('id')->merge($inHouse->pluck('id')))->where('status', '!=', 'void')
            ->get(['reservation_id', 'balance'])->groupBy('reservation_id')
            ->map(fn ($bills) => Money::sum($bills->pluck('balance')->map(fn ($v) => (string) $v)));
        $present = fn ($r) => ReservationPresenter::summary($r, $hotel) + [
            'late' => $r->isActive() && $r->status !== 'checked_in' && now()->gt($r->check_in_at),
            'overdue' => $r->status === 'checked_in' && now()->gt($r->check_out_at),
            'balance' => $balances[$r->id] ?? '0.00',
        ];

        return response()->json([
            'arrivals' => $arrivals->map($present),
            'departures' => $departures->map($present),
            'in_house' => $inHouse->map($present),
        ]);
    }

    /** What the check-in screen needs: clean free rooms per booked room, fees and money due. */
    public function checkInInfo(Request $request, string $id): JsonResponse
    {
        $reservation = $this->reservation($request, $id);
        $hotel = $request->user()->hotel;
        $early = $this->desk->earlyFee($reservation, now());

        $rooms = $reservation->rooms()->with(['roomType:id,name', 'room:id,number,status', 'rates'])->get()->map(function (ReservationRoom $rr) use ($reservation, $early) {
            $from = now()->lt($rr->check_in_at) ? now() : $rr->check_in_at;
            $free = $this->availability->freeRooms($reservation->branch_id, $rr->room_type_id, $from, $rr->blocked_until, $reservation->id)
                ->where('status', 'available')->values();
            $night = (string) ($rr->rates->first()?->amount ?? '0');

            return [
                'id' => $rr->id, 'room_type' => $rr->roomType?->name, 'room_id' => $rr->room_id, 'room_number' => $rr->room?->number, 'room_status' => $rr->room?->status,
                'free_rooms' => $free->map(fn (Room $r) => ['id' => $r->id, 'number' => $r->number, 'floor' => $r->floor]),
                'early_fee' => $early ? ($early['type'] === 'fixed' ? Money::round($early['value']) : Money::percentOf($night, $early['value'])) : null,
                // true: arrived after midnight, so the "fee" is a full night (last night)
                'early_is_night' => ($early['type'] ?? null) === 'night',
            ];
        });

        return response()->json(['data' => [
            'reservation' => ReservationPresenter::detail($reservation, $hotel),
            'rooms' => $rooms,
            'id_photo_needed' => app(GuestIdPhotos::class)->missingFor($reservation),
            'deposit_due' => $reservation->status === 'pending' ? Money::max(Money::sub((string) $reservation->deposit_required, (string) $reservation->paid_total), '0') : '0.00',
        ]]);
    }

    public function checkIn(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'rooms' => ['nullable', 'array'], 'rooms.*.id' => ['required', 'integer'], 'rooms.*.room_id' => ['nullable', 'integer'],
            'waive_early_fee' => ['boolean'], ...$this->paymentRules(),
        ]);
        $reservation = $this->desk->checkIn($this->reservation($request, $id), $data, $request->user());

        return response()->json(['data' => ReservationPresenter::detail($reservation, $request->user()->hotel)]);
    }

    /** What the check-out screen needs: late fee and the bills. */
    public function checkOutInfo(Request $request, string $id): JsonResponse
    {
        $reservation = $this->reservation($request, $id);
        $alreadyCharged = Invoice::where('reservation_id', $reservation->id)->where('status', '!=', 'void')
            ->whereHas('items', fn ($q) => $q->where('type', 'late_check_out'))->exists();
        $late = $alreadyCharged ? null : $this->desk->lateFee($reservation, now());
        $lateAmount = '0.00';
        // Hourly and day-use stays past their end time: the extra hours.
        foreach ($alreadyCharged ? [] : $this->desk->overstay($reservation, now()) as $line) {
            $fee = Money::mul($line['unit_price'], $line['quantity']);
            $taxes = collect(app(TaxCalculator::class)->forLine('late_check_out', $fee))->where('is_inclusive', false)->pluck('amount')->all();
            $lateAmount = Money::add($lateAmount, Money::add($fee, Money::sum($taxes)));
            $late = ['type' => 'hours'];
        }
        if ($late && $late['type'] !== 'hours') {
            foreach ($reservation->rooms()->with('rates')->get() as $rr) {
                $night = (string) ($rr->rates->last()?->amount ?? '0');
                $fee = $late['type'] === 'fixed' ? Money::round($late['value']) : Money::percentOf($night, $late['value']);
                // With the taxes added on top, so "balance + late fee" is exactly what the guest pays.
                $taxes = collect(app(TaxCalculator::class)->forLine('late_check_out', $fee))->where('is_inclusive', false)->pluck('amount')->all();
                $lateAmount = Money::add($lateAmount, Money::add($fee, Money::sum($taxes)));
            }
        }

        return response()->json(['data' => [
            'reservation' => ReservationPresenter::detail($reservation, $request->user()->hotel),
            'late_fee' => $late ? $lateAmount : null,
            'balance' => FrontDesk::balance($reservation),
            // Leaving before the booked date: the nights not used come off the bill, the fee is added.
            'early' => $this->desk->earlyDeparturePreview($reservation, $request->user()),
        ]]);
    }

    public function checkOut(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['waive_late_fee' => ['boolean'], 'waive_early_fee' => ['boolean'], 'approve_balance' => ['boolean'], ...$this->paymentRules()]);
        $reservation = $this->desk->checkOut($this->reservation($request, $id), $data, $request->user());

        return response()->json(['data' => ReservationPresenter::detail($reservation, $request->user()->hotel)]);
    }

    public function walkIn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'stay_type' => ['required', Rule::in(Reservation::STAY_TYPES)],
            'check_out' => ['nullable', 'date', 'before:+3 years'],
            'hours' => ['nullable', 'integer', 'min:1', 'max:24'],
            // "Not sure yet": one night now, one more at each check-out time while the guest stays (Q16).
            'open_stay' => ['nullable', 'boolean'],
            'guest_id' => ['required_without:quick_guest', 'nullable', 'integer'],
            // Quick check-in: only a name and phone now, the rest later (the guest shows "Details missing").
            'pay_later' => ['nullable', 'boolean'],
            'quick_guest' => ['nullable', 'array'],
            'quick_guest.name' => ['required_with:quick_guest', 'string', 'min:2', 'max:120'],
            'quick_guest.phone' => ['required_with:quick_guest', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{6,30}$/'],
            'rooms' => ['required', 'array', 'min:1', 'max:10'],
            'rooms.*.room_type_id' => ['required', 'integer'],
            'rooms.*.room_id' => ['required', 'integer'],
            'rooms.*.adults' => ['nullable', 'integer', 'between:1,20'],
            'rooms.*.children' => ['nullable', 'integer', 'between:0,20'],
            'discount_percent' => ['nullable', 'decimal:0,2', 'between:0,100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            ...$this->paymentRules(),
        ]);
        $user = $request->user();
        if (! empty($data['quick_guest'])) {
            abort_unless($user->hasPermission('frontdesk.quick_check_in') && $user->hasPermission('guests.create_edit'), 403, 'Your role cannot use quick check-in.');
        }
        $reservation = DB::transaction(function () use ($data, $user) {
            if (! empty($data['quick_guest'])) {
                $parts = preg_split('/\s+/', trim($data['quick_guest']['name']), 2);
                $data['quick'] = true;
                $data['guest_id'] = Guest::create(['first_name' => mb_substr($parts[0], 0, 60), 'last_name' => isset($parts[1]) ? mb_substr($parts[1], 0, 60) : null, 'phone' => trim($data['quick_guest']['phone'])])->id;
            }
            unset($data['quick_guest']);

            return $this->desk->walkIn($data, $user);
        });

        return response()->json(['data' => ReservationPresenter::detail($reservation, $user->hotel)], 201);
    }

    /** Free, clean rooms right now (for walk-ins), with today's price. */
    public function walkInRooms(Request $request): JsonResponse
    {
        $branch = $this->branch($request);
        $hotel = $request->user()->hotel;
        $settings = app(HotelSettings::class)->group(HotelSettings::BOOKING_DEFAULTS);
        $data = $request->validate([
            'stay_type' => ['nullable', 'in:nightly,day_use,hourly'],
            'check_out' => ['nullable', 'date_format:Y-m-d', 'before:+3 years'],
            'hours' => ['nullable', 'integer', 'between:1,24'],
        ]);
        // Only rooms free for the whole stay: a clean room booked from tomorrow is not offered for three nights.
        $zone = HotelClock::zone($hotel);
        $until = match ($data['stay_type'] ?? 'nightly') {
            'hourly' => now()->addHours((int) ($data['hours'] ?? 1))->addMinutes((int) $settings['hourly_cleaning_minutes']),
            'day_use' => now($zone)->setTime(22, 0)->utc()->max(now()->addHour()),
            default => isset($data['check_out'])
                ? Carbon::parse($data['check_out'], $zone)->setTimeFromTimeString((string) ($branch->check_out_time ?: '12:00'))->utc()->max(now()->addHour())
                : now()->addHour(),
        };

        return response()->json([
            'data' => $this->availability->freeRooms($branch->id, null, now(), $until)->where('status', 'available')
                ->load('roomType')->map(fn (Room $r) => [
                    'id' => $r->id, 'number' => $r->number, 'floor' => $r->floor, 'room_type_id' => $r->room_type_id, 'room_type' => $r->roomType?->name,
                    'nightly_price' => $r->roomType?->nightly_price, 'day_use_price' => $r->roomType?->day_use_price, 'hourly_price' => $r->roomType?->hourly_price,
                    'max_capacity' => $r->roomType?->max_capacity, 'currency' => $r->roomType?->currency,
                ])->values(),
            'walk_in_payment' => $settings['walk_in_payment'],
            'now' => HotelClock::iso(now(), $hotel),
            'night_date' => $this->desk->nightDate($hotel), // the night a walk-in arriving now pays for
        ]);
    }

    public function move(Request $request, string $id, string $roomStay): JsonResponse
    {
        $reservation = $this->reservation($request, $id);
        $rr = $reservation->rooms()->findOrFail($roomStay);
        $data = $request->validate(['room_id' => ['required', 'integer']]);
        $reservation = $this->desk->moveRoom($reservation, $rr, (int) $data['room_id'], $request->user());

        return response()->json(['data' => ReservationPresenter::detail($reservation, $request->user()->hotel)]);
    }

    public function extend(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['check_out' => ['nullable', 'date', 'before:+3 years'], 'hours' => ['nullable', 'integer', 'between:1,24']]);
        $reservation = $this->desk->extend($this->reservation($request, $id), $data, $request->user());

        return response()->json(['data' => ReservationPresenter::detail($reservation, $request->user()->hotel)]);
    }

    public function roomStatus(Request $request, string $id): JsonResponse
    {
        $room = Room::whereIn('branch_id', $request->user()->accessibleBranches()->pluck('id'))->findOrFail($id);
        $data = $request->validate(['status' => ['required', Rule::in(Room::STATUSES)]]);

        return response()->json(['data' => $this->desk->setRoomStatus($room, $data['status'], $request->user())]);
    }

    /** Manager allows check-out with money still owed (Q8). */
    public function approveBalance(Request $request, string $id, InvoiceService $invoices): JsonResponse
    {
        $reservation = $this->reservation($request, $id);
        foreach ($reservation->invoices()->where('status', '!=', 'void')->get() as $inv) {
            if (Money::isPositive((string) $inv->balance)) {
                $invoices->approveBalance($inv, $request->user());
            }
        }

        return response()->json(['data' => ReservationPresenter::detail($reservation->fresh(), $request->user()->hotel)]);
    }

    // ------------------------------------------------------------ helpers

    /** @return array<string, mixed> */
    private function paymentRules(): array
    {
        return [
            'payment' => ['nullable', 'array'],
            'payment.payment_method_id' => ['nullable', 'integer'],
            'payment.amount' => ['nullable', 'decimal:0,2', 'min:0', 'max:99999999'],
            'payment.currency' => ['nullable', 'string', 'size:3'],
            'payment.reference' => ['nullable', 'string', 'max:100'],
            'payment.proof_id' => ['nullable', 'integer'],
            'payment.confirm_overpay' => ['nullable', 'boolean'],
        ];
    }

    private function branch(Request $request)
    {
        $branches = $request->user()->accessibleBranches();
        $branch = $request->input('branch_id') ? $branches->firstWhere('id', (int) $request->input('branch_id')) : $branches->first();
        if (! $branch) {
            throw ValidationException::withMessages(['branch_id' => 'Choose one of your branches.']);
        }

        return $branch;
    }

    private function reservation(Request $request, string $id): Reservation
    {
        return Reservation::whereIn('branch_id', $request->user()->accessibleBranches()->pluck('id'))->findOrFail($id);
    }
}
