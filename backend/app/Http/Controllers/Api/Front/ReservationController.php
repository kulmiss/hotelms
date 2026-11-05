<?php

namespace App\Http\Controllers\Api\Front;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ReservationPresenter;
use App\Models\CancellationPolicy;
use App\Models\HotelCurrency;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Services\Booking\Availability;
use App\Services\Booking\ReservationService;
use App\Services\Booking\StayQuote;
use App\Support\HotelClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Reservations (workflows.md W1, W2) and the reservation calendar. */
class ReservationController extends Controller
{
    public function __construct(private ReservationService $service, private Availability $availability, private StayQuote $quote) {}

    public function index(Request $request): JsonResponse
    {
        $hotel = $request->user()->hotel;
        $branches = $request->user()->accessibleBranches()->pluck('id');
        $q = trim((string) $request->input('q'));

        $page = Reservation::query()->with(['guest:id,first_name,last_name,phone', 'branch:id,name', 'rooms.roomType:id,name', 'rooms.room:id,number'])
            ->whereIn('branch_id', $branches)
            ->when($request->input('branch_id'), fn ($w, $b) => $w->where('branch_id', $b))
            ->when($request->input('status'), fn ($w, $s) => $w->whereIn('status', explode(',', $s)))
            ->when($request->boolean('expiring'), fn ($w) => $w->where('status', 'pending')->where('expires_at', '<=', now()->addHours(6)))
            ->when($request->input('from'), fn ($w, $d) => $w->where('check_out_at', '>', HotelClock::parse($d, $hotel)))
            ->when($request->input('to'), fn ($w, $d) => $w->where('check_in_at', '<', HotelClock::parse($d, $hotel)->addDay()))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('code', 'like', "%{$q}%")->orWhereHas('guest', fn ($g) => $g->search($q))))
            ->orderBy($request->input('sort') === 'newest' ? 'created_at' : 'check_in_at', $request->input('sort') === 'newest' ? 'desc' : 'asc')
            ->paginate(min(100, (int) $request->input('per_page', 25)));

        $page->getCollection()->transform(fn (Reservation $r) => ReservationPresenter::summary($r, $hotel));

        return response()->json($page);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $reservation = $this->find($request, $id);

        return response()->json(['data' => ReservationPresenter::detail($reservation, $request->user()->hotel)]);
    }

    /** Lists the booking screens need (front-desk staff may not open Settings). */
    public function lookups(): JsonResponse
    {
        return response()->json([
            'policies' => CancellationPolicy::orderByDesc('is_default')->orderBy('id')->get(['id', 'name', 'free_until_hours', 'penalty_type', 'penalty_value', 'is_default']),
            'payment_methods' => PaymentMethod::where('is_active', true)->orderBy('id')->get(['id', 'name', 'type']),
            'currencies' => HotelCurrency::orderByDesc('is_default')->orderBy('code')->get(['code', 'symbol', 'is_default']),
        ]);
    }

    /** Free room types (with price) and room numbers for a stay. */
    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate($this->stayRules());
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
        $data = $request->validate(['guest_id' => ['nullable', 'integer'], 'guest_type_id' => ['nullable', 'integer'], 'walk_in' => ['boolean']] + $this->bookingRules());

        return response()->json(['data' => $this->service->preview($data, $request->user(), $request->integer('reservation_id') ? $this->find($request, (string) $request->integer('reservation_id')) : null)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->bookingRules() + ['source' => ['nullable', Rule::in(Reservation::SOURCES)], 'notes' => ['nullable', 'string', 'max:1000']]);
        $reservation = $this->service->create($data, $request->user());

        return response()->json(['data' => ReservationPresenter::detail($reservation, $request->user()->hotel)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $reservation = $this->find($request, $id);
        $data = $request->validate($this->bookingRules(false) + ['source' => ['nullable', Rule::in(Reservation::SOURCES)], 'notes' => ['nullable', 'string', 'max:1000']]);
        $reservation = $this->service->update($reservation, $data, $request->user());

        return response()->json(['data' => ReservationPresenter::detail($reservation, $request->user()->hotel)]);
    }

    public function cancellationTerms(Request $request, string $id): JsonResponse
    {
        $reservation = $this->find($request, $id);

        return response()->json(['data' => $this->service->cancellationTerms($reservation, $request->boolean('no_show'))]);
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
        $reservation = $this->service->cancel($reservation, (string) $request->input('reason', 'No-show'), $request->user(), true);

        return response()->json(['data' => ReservationPresenter::detail($reservation, $request->user()->hotel)]);
    }

    public function payment(Request $request, string $id): JsonResponse
    {
        $reservation = $this->find($request, $id);
        $data = $request->validate([
            'type' => ['required', Rule::in(Payment::TYPES)],
            'payment_method_id' => ['required', 'integer'],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'reference' => ['nullable', 'string', 'max:100'],
            'refund_reason' => ['nullable', 'required_if:type,refund', 'string', 'max:200'],
        ]);
        $permission = $data['type'] === 'refund' ? 'payments.refund' : 'payments.take';
        abort_unless($request->user()->hasPermission($permission), 403, 'You may not do this.');

        $this->service->recordPayment($reservation, $data, $request->user());

        return response()->json(['data' => ReservationPresenter::detail($reservation->fresh(), $request->user()->hotel)], 201);
    }

    /** Rooms × days for a branch, with the bookings on each room (and unassigned ones per type). */
    public function calendar(Request $request): JsonResponse
    {
        $data = $request->validate(['branch_id' => ['required', 'integer'], 'from' => ['required', 'date'], 'days' => ['nullable', 'integer', 'between:1,62']]);
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
            'check_in' => ['required', 'date'],
            'check_out' => ['nullable', 'date'],
            'hours' => ['nullable', 'integer', 'min:1'],
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
