<?php

namespace App\Http\Controllers\Api\Front;

use App\Http\Controllers\Controller;
use App\Http\Presenters\InvoicePresenter;
use App\Http\Presenters\ReservationPresenter;
use App\Models\CancellationPolicy;
use App\Models\Guest;
use App\Models\HotelCurrency;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Services\Booking\Availability;
use App\Services\Booking\ReservationService;
use App\Services\Booking\StayQuote;
use App\Services\GuestIdPhotos;
use App\Services\Switches;
use App\Support\HotelClock;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Reservations (workflows.md W1, W2) and the reservation calendar. */
class ReservationController extends Controller
{
    /** Plain words for the date checks (not "must be a date before +2 years"). */
    private const DATE_MESSAGES = [
        'check_in.date' => 'Choose a check-in date.',
        'check_in.after' => 'Choose a check-in date.',
        'check_in.before' => 'Bookings can be made at most 2 years ahead.',
        'check_out.date' => 'Choose a check-out date.',
        'check_out.before' => 'Bookings can be made at most 2 years ahead.',
    ];

    public function __construct(private ReservationService $service, private Availability $availability, private StayQuote $quote) {}

    public function index(Request $request): JsonResponse|Response
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'q' => ['nullable', 'string', 'max:100'], 'branch_id' => ['nullable', 'integer'], 'status' => ['nullable', 'string', 'max:100']]);
        $hotel = $request->user()->hotel;
        $branches = $request->user()->accessibleBranches()->pluck('id');
        $q = trim((string) $request->input('q'));

        $query = ReservationPresenter::withBills(Reservation::query())->with(['guest:id,first_name,last_name,phone', 'branch:id,name', 'rooms.roomType:id,name', 'rooms.room:id,number'])
            ->whereIn('branch_id', $branches)
            ->when($request->input('branch_id'), fn ($w, $b) => $w->where('branch_id', $b))
            ->when($request->input('status'), fn ($w, $s) => $w->whereIn('status', explode(',', $s)))
            ->when($request->boolean('expiring'), fn ($w) => $w->where('status', 'pending')->where('expires_at', '<=', now()->addHours(6)))
            ->when($request->input('from'), fn ($w, $d) => $w->where('check_out_at', '>', HotelClock::parse($d, $hotel)))
            ->when($request->input('to'), fn ($w, $d) => $w->where('check_in_at', '<', HotelClock::parse($d, $hotel)->addDay()))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('code', 'like', "%{$q}%")->orWhereHas('guest', fn ($g) => $g->search($q))));
        $newest = $request->input('sort') === 'newest'; // older screens ask for "newest"
        ListQuery::sort($query, $request, [
            'check_in' => 'check_in_at', 'check_out' => 'check_out_at', 'code' => 'code', 'status' => 'status', 'newest' => 'created_at', 'created' => 'created_at', 'total' => 'total',
            'guest' => fn ($q, $dir) => $q->orderBy(Guest::select('last_name')->whereColumn('guests.id', 'reservations.guest_id')->limit(1), $dir),
        ], $newest ? 'newest' : 'check_in', $newest ? 'desc' : 'asc');
        if (ListQuery::wantsFile($request)) {
            return ListQuery::file($request, $query, 'Bookings', ['Booking', 'Guest', 'Phone', 'Branch', 'Status', 'Check-in', 'Check-out', 'Rooms', 'Total', 'Paid', 'Currency'], function (Reservation $r) use ($hotel) {
                [$total, $paid] = ReservationPresenter::money($r);

                return [$r->code, $r->guest?->full_name, $r->guest?->phone, $r->branch?->name, $r->status, HotelClock::local($r->check_in_at, $hotel)->format('Y-m-d H:i'),
                    HotelClock::local($r->check_out_at, $hotel)->format('Y-m-d H:i'), $r->rooms->map(fn ($rr) => $rr->room?->number ?? $rr->roomType?->name)->implode(', '), $total, $paid, $r->currency];
            });
        }
        $page = $query->paginate(ListQuery::perPage($request));

        $page->getCollection()->transform(fn (Reservation $r) => ReservationPresenter::summary($r, $hotel));

        return response()->json($page);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $reservation = $this->find($request, $id);

        return response()->json(['data' => ReservationPresenter::detail($reservation, $request->user()->hotel)]);
    }

    /** Registration card for the guest to sign at check-in (decisions.md W9). */
    public function registrationCard(Request $request, string $id): JsonResponse
    {
        $reservation = $this->find($request, $id);
        $hotel = $request->user()->hotel;
        $detail = ReservationPresenter::detail($reservation, $hotel);
        $guest = $reservation->guest;

        return response()->json(['data' => [
            'reservation' => $detail,
            'guest' => $guest ? [
                'name' => $guest->full_name, 'phone' => $guest->phone, 'email' => $guest->email, 'nationality' => $guest->nationality,
                'id_type' => $guest->id_type,
                // The full ID number only for people who check guests in (owner Q5); guest viewers see its end.
                'id_number' => $request->user()->hasPermission('guests.create_edit') ? $guest->id_number : ($request->user()->hasPermission('guests.view') ? Guest::maskId($guest->id_number) : null),
                'date_of_birth' => $guest->date_of_birth?->toDateString(),
            ] : null,
            'hotel' => InvoicePresenter::printHeader($hotel),
            'printed_by' => $request->user()->name,
        ]]);
    }

    /** Lists the booking screens need (front-desk staff may not open Settings). */
    public function lookups(Request $request, Switches $switches): JsonResponse
    {
        $hotel = $request->user()->hotel;
        $multi = $switches->on($hotel, 'feature.multi_currency');

        return response()->json([
            'stay_types' => array_values(array_filter(Reservation::STAY_TYPES, fn ($t) => match ($t) {
                'hourly' => $switches->on($hotel, 'feature.hourly_stays'),
                'day_use' => $switches->on($hotel, 'feature.day_use'),
                default => true,
            })),
            'policies' => CancellationPolicy::orderByDesc('is_default')->orderBy('id')->get(['id', 'name', 'free_until_hours', 'penalty_type', 'penalty_value', 'is_default']),
            'payment_methods' => PaymentMethod::where('is_active', true)->orderBy('id')->get(['id', 'name', 'type']),
            // May this person let a guest pay everything at check-out (no deposit, no up-front payment)?
            'pay_later' => $this->service->canPayLater($request->user()),
            // Guest ID photo: turned on, and needed before check-in?
            'id_photo' => app(GuestIdPhotos::class)->rules(),
            'currencies' => HotelCurrency::orderByDesc('is_default')->orderBy('code')->when(! $multi, fn ($q) => $q->where('is_default', true))->get(['code', 'symbol', 'is_default']),
        ]);
    }

    /** Free room types (with price) and room numbers for a stay. */
    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate($this->stayRules(), self::DATE_MESSAGES);
        $user = $request->user();
        $hotel = $user->hotel;
        $branch = $user->accessibleBranches()->firstWhere('id', (int) $data['branch_id']);
        if (! $branch) {
            throw ValidationException::withMessages(['branch_id' => 'Choose one of your branches.']);
        }
        $times = $this->quote->times($hotel, $branch, $data['stay_type'], $data['check_in'], $data['check_out'] ?? null, $data['hours'] ?? null);
        $ignore = $request->integer('reservation_id') ?: null;
        $guestTypeId = $request->integer('guest_type_id') ?: null;
        $free = $this->availability->freeRooms($branch->id, null, $times['in'], $times['until'], $ignore)->groupBy('room_type_id');

        $types = $this->availability->forBranch($branch->id, $times['in'], $times['until'], $ignore)->map(function ($row) use ($hotel, $data, $times, $guestTypeId, $free) {
            $type = $row['room_type'];
            try {
                $price = $this->quote->price($hotel, $type, $data['stay_type'], $times['in'], $times['out'], $guestTypeId);
            } catch (ValidationException) {
                $price = null; // not sold for this stay type
            }

            return [
                'room_type_id' => $type->id, 'name' => $type->name, 'max_capacity' => $type->max_capacity, 'base_capacity' => $type->base_capacity,
                'total_rooms' => $row['total'], 'available' => $price ? $row['available'] : 0, 'offered' => $price !== null,
                'price' => $price['total'] ?? null, 'lines' => $price['lines'] ?? [], 'currency' => $type->currency,
                'free_rooms' => ($free->get($type->id) ?? collect())->map(fn (Room $r) => ['id' => $r->id, 'number' => $r->number, 'floor' => $r->floor, 'status' => $r->status])->values(),
            ];
        });

        return response()->json(['data' => $types, 'check_in_at' => HotelClock::iso($times['in'], $hotel), 'check_out_at' => HotelClock::iso($times['out'], $hotel)]);
    }

    /** Full price of a booking before saving it. */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate(['guest_id' => ['nullable', 'integer'], 'guest_type_id' => ['nullable', 'integer'], 'walk_in' => ['boolean']] + $this->bookingRules(), self::DATE_MESSAGES);

        return response()->json(['data' => $this->service->preview($data, $request->user(), $request->integer('reservation_id') ? $this->find($request, (string) $request->integer('reservation_id')) : null)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->bookingRules() + ['source' => ['nullable', Rule::in(Reservation::SOURCES)], 'notes' => ['nullable', 'string', 'max:1000'], 'pay_later' => ['nullable', 'boolean']], self::DATE_MESSAGES);
        $reservation = $this->service->create($data, $request->user());

        return response()->json(['data' => ReservationPresenter::detail($reservation, $request->user()->hotel)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $reservation = $this->find($request, $id);
        $data = $request->validate($this->bookingRules(false) + ['source' => ['nullable', Rule::in(Reservation::SOURCES)], 'notes' => ['nullable', 'string', 'max:1000']], self::DATE_MESSAGES);
        $reservation = $this->service->update($reservation, $data, $request->user());

        return response()->json(['data' => ReservationPresenter::detail($reservation, $request->user()->hotel)]);
    }

    public function cancellationTerms(Request $request, string $id): JsonResponse
    {
        $reservation = $this->find($request, $id);

        return response()->json(['data' => $this->service->cancellationTerms($reservation, $request->boolean('no_show'))]);
    }

    /** Confirm a pending booking without a deposit: the guest pays at check-out. */
    public function payLater(Request $request, string $id): JsonResponse
    {
        $reservation = $this->service->payLater($this->find($request, $id), $request->user());

        return response()->json(['data' => ReservationPresenter::detail($reservation, $request->user()->hotel)]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $reservation = $this->find($request, $id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);
        $reservation = $this->service->cancel($reservation, $data['reason'], $request->user());

        return response()->json(['data' => ReservationPresenter::detail($reservation, $request->user()->hotel)]);
    }

    public function noShow(Request $request, string $id): JsonResponse
    {
        $reservation = $this->find($request, $id);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $reservation = $this->service->cancel($reservation, $data['reason'] ?? 'No-show', $request->user(), true);

        return response()->json(['data' => ReservationPresenter::detail($reservation, $request->user()->hotel)]);
    }

    public function payment(Request $request, string $id): JsonResponse
    {
        $reservation = $this->find($request, $id);
        $data = $request->validate([
            'type' => ['required', Rule::in(Payment::TYPES)],
            'payment_method_id' => ['required', 'integer'],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:99999999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'reference' => ['nullable', 'string', 'max:100'],
            'proof_id' => ['nullable', 'integer'],
            'refund_reason' => ['nullable', 'required_if:type,refund', 'string', 'max:200'],
            'confirm_overpay' => ['nullable', 'boolean'], // yes, more than the balance
        ]);
        $permission = $data['type'] === 'refund' ? 'payments.refund' : 'payments.take';
        abort_unless($request->user()->hasPermission($permission), 403, 'You may not do this.');

        $this->service->recordPayment($reservation, $data, $request->user());

        return response()->json(['data' => ReservationPresenter::detail($reservation->fresh(), $request->user()->hotel)], 201);
    }

    /** Rooms × days for a branch, with the bookings on each room (and unassigned ones per type). */
    public function calendar(Request $request): JsonResponse
    {
        $data = $request->validate(['branch_id' => ['required', 'integer'], 'from' => ['required', 'date'], 'days' => ['nullable', 'integer', 'between:1,62'], 'room_id' => ['nullable', 'integer']]);
        $user = $request->user();
        $hotel = $user->hotel;
        $branch = $user->accessibleBranches()->firstWhere('id', (int) $data['branch_id']);
        abort_unless($branch, 404);
        $days = (int) ($data['days'] ?? 14);
        $from = Carbon::parse($data['from'], HotelClock::zone($hotel))->startOfDay();
        $to = $from->copy()->addDays($days);

        $rooms = Room::with('roomType:id,name')->where('branch_id', $branch->id)->orderByRaw('LENGTH(number)')->orderBy('number')->get(['id', 'number', 'floor', 'status', 'room_type_id']);
        $bookings = ReservationRoom::with(['reservation:id,code,status,guest_id,stay_type', 'reservation.guest:id,first_name,last_name', 'roomType:id,name'])
            ->whereHas('reservation', fn ($q) => $q->where('branch_id', $branch->id))
            ->when($data['room_id'] ?? null, fn ($q, $room) => $q->where('room_id', (int) $room)) // one room's calendar
            ->whereIn('status', [...Reservation::ACTIVE, 'checked_out'])
            ->where('check_in_at', '<', $to->copy()->utc())->where('check_out_at', '>', $from->copy()->utc())
            ->get()
            ->map(fn (ReservationRoom $rr) => [
                'id' => $rr->id, 'reservation_id' => $rr->reservation_id, 'code' => $rr->reservation?->code,
                'guest' => $rr->reservation?->guest?->full_name, 'status' => $rr->reservation?->status, 'stay_type' => $rr->reservation?->stay_type,
                'room_id' => $rr->room_id, 'room_type_id' => $rr->room_type_id, 'room_type' => $rr->roomType?->name,
                'check_in_at' => HotelClock::iso($rr->check_in_at, $hotel), 'check_out_at' => HotelClock::iso($rr->check_out_at, $hotel),
            ]);

        return response()->json([
            'from' => $from->toDateString(),
            'days' => $days,
            'rooms' => $rooms->map(fn (Room $r) => ['id' => $r->id, 'number' => $r->number, 'floor' => $r->floor, 'status' => $r->status, 'room_type_id' => $r->room_type_id, 'room_type' => $r->roomType?->name]),
            'bookings' => $bookings,
        ]);
    }

    // ------------------------------------------------------------ helpers

    /** @return array<string, mixed> */
    private function stayRules(): array
    {
        return [
            'branch_id' => ['required', 'integer'],
            'stay_type' => ['required', Rule::in(Reservation::STAY_TYPES)],
            'check_in' => ['required', 'date', 'after:2000-01-01', 'before:+2 years'],
            'check_out' => ['nullable', 'date', 'before:+3 years'],
            'hours' => ['nullable', 'integer', 'min:1', 'max:24'],
        ];
    }

    /** @return array<string, mixed> */
    private function bookingRules(bool $withBranch = true): array
    {
        $rules = $this->stayRules() + [
            'guest_id' => ['required', 'integer'],
            'rooms' => ['required', 'array', 'min:1', 'max:30'],
            'rooms.*.room_type_id' => ['required', 'integer'],
            'rooms.*.room_id' => ['nullable', 'integer'],
            'rooms.*.adults' => ['nullable', 'integer', 'between:1,20'],
            'rooms.*.children' => ['nullable', 'integer', 'between:0,20'],
            'discount_percent' => ['nullable', 'decimal:0,2', 'between:0,100'],
            'cancellation_policy_id' => ['nullable', 'integer'],
        ];
        if (! $withBranch) {
            $rules['branch_id'] = ['nullable', 'integer'];
        }

        return $rules;
    }

    private function find(Request $request, string $id): Reservation
    {
        return Reservation::whereIn('branch_id', $request->user()->accessibleBranches()->pluck('id'))->findOrFail($id);
    }
}
