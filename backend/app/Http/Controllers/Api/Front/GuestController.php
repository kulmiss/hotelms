<?php

namespace App\Http\Controllers\Api\Front;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\GuestType;
use App\Models\Reservation;
use App\Support\HotelClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Guests are shared by all branches of the hotel (database-schema.md rule 1). */
class GuestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $guests = Guest::query()->with('guestType:id,name')
            ->search($request->input('q'))
            ->when($request->boolean('blacklisted'), fn ($q) => $q->where('is_blacklisted', true))
            ->withCount('reservations')
            ->orderByDesc('id')
            ->paginate(max(1, min(100, (int) $request->input('per_page', 25))))
            // The list shows only the end of the ID number; the full number is on the guest's own page.
            ->through(fn (Guest $g) => $g->setAttribute('id_number', Guest::maskId($g->id_number)));

        return response()->json($guests);
    }

    /** Guest types for the guest form (front-desk staff may not open Settings). */
    public function types(): JsonResponse
    {
        return response()->json(['data' => GuestType::orderBy('name')->get(['id', 'name'])]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $guest = Guest::with('guestType:id,name')->findOrFail($id);
        $hotel = $request->user()->hotel;
        $stays = Reservation::where('guest_id', $guest->id)->with('branch:id,name')->latest('check_in_at')->limit(50)->get()
            ->map(fn (Reservation $r) => [
                'id' => $r->id, 'code' => $r->code, 'branch' => $r->branch?->name, 'status' => $r->status, 'stay_type' => $r->stay_type,
                'check_in_at' => HotelClock::iso($r->check_in_at, $hotel), 'check_out_at' => HotelClock::iso($r->check_out_at, $hotel),
                'total' => $r->total, 'paid_total' => $r->paid_total, 'currency' => $r->currency,
            ]);

        return response()->json(['data' => $guest, 'stays' => $stays]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $duplicate = $this->duplicate($data);

        return response()->json(['data' => Guest::create($data)->load('guestType:id,name'), 'possible_duplicate' => $duplicate], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $guest = Guest::findOrFail($id);
        $guest->update($request->validate($this->rules()));

        return response()->json(['data' => $guest->fresh('guestType:id,name')]);
    }

    public function blacklist(Request $request, string $id): JsonResponse
    {
        $guest = Guest::findOrFail($id);
        $data = $request->validate(['is_blacklisted' => ['required', 'boolean'], 'reason' => ['nullable', 'required_if:is_blacklisted,true', 'string', 'max:200']]);
        $guest->update(['is_blacklisted' => $data['is_blacklisted'], 'blacklist_reason' => $data['is_blacklisted'] ? $data['reason'] : null]);

        return response()->json(['data' => $guest->fresh()]);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{6,30}$/'],
            'email' => ['nullable', 'email', 'max:120'],
            'nationality' => ['nullable', 'string', 'max:60'],
            'id_type' => ['nullable', Rule::in(Guest::ID_TYPES)],
            'id_number' => ['nullable', 'string', 'max:60'],
            'gender' => ['nullable', Rule::in(['female', 'male'])],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'guest_type_id' => ['nullable', 'integer', Rule::in(GuestType::pluck('id')->all())],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** A guest with the same phone or ID number already exists (shown as a hint, not blocked). */
    private function duplicate(array $data): ?array
    {
        $match = Guest::query()
            ->where(function ($q) use ($data) {
                $q->whereRaw('1 = 0');
                if (! empty($data['phone'])) {
                    $q->orWhere('phone', $data['phone']);
                }
                if (! empty($data['id_number'])) {
                    $q->orWhere('id_number_hash', Guest::idHash($data['id_number']));
                }
            })->first();

        return $match ? ['id' => $match->id, 'name' => $match->full_name] : null;
    }
}
