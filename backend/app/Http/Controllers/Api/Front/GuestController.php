<?php

namespace App\Http\Controllers\Api\Front;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\GuestIdPhoto;
use App\Models\GuestType;
use App\Models\Reservation;
use App\Services\GuestIdPhotos;
use App\Support\HotelClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Guests are shared by all branches of the hotel (database-schema.md rule 1). */
class GuestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $guests = Guest::query()->with('guestType:id,name')
            ->search($request->input('q'))
            ->when($request->boolean('blacklisted'), fn ($q) => $q->where('is_blacklisted', true))
            ->when($request->boolean('missing'), fn ($q) => $q->detailsMissing())
            ->withCount('reservations')
            ->orderByDesc('id')
            ->paginate(max(1, min(100, (int) $request->input('per_page', 25))))
            // The list shows only the end of the ID number; the full number is on the guest's own page.
            // (Plain arrays, so the masked number is not encrypted again just to be shown.)
            ->through(fn (Guest $g) => ['id_number' => Guest::maskId($g->id_number)] + $g->toArray());

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

        $photo = GuestIdPhoto::where('guest_id', $guest->id)->first(['id', 'mime', 'created_at']);

        return response()->json(['data' => $guest, 'stays' => $stays, 'id_photo' => $photo ? ['mime' => $photo->mime, 'at' => HotelClock::iso($photo->created_at, $hotel)] : null]);
    }

    /** Take or upload a photo of the guest's ID or passport (replaces the old one). */
    public function storeIdPhoto(Request $request, string $id, GuestIdPhotos $photos): JsonResponse
    {
        $guest = Guest::findOrFail($id);
        $request->validate(['file' => ['required', 'file', 'max:8192', 'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf']]);
        $photo = $photos->save($guest, $request->file('file'), $request->user());

        return response()->json(['data' => ['mime' => $photo->mime, 'at' => HotelClock::iso($photo->created_at, $request->user()->hotel)]], 201);
    }

    /** The ID photo itself (decrypted), only for staff who may see guests of this hotel. */
    public function idPhoto(string $id, GuestIdPhotos $photos): Response
    {
        $guest = Guest::findOrFail($id);
        $photo = GuestIdPhoto::where('guest_id', $guest->id)->firstOrFail();
        $body = $photos->contents($photo);
        abort_if($body === null, 404);

        return response($body, 200, ['Content-Type' => $photo->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline']);
    }

    public function destroyIdPhoto(string $id, GuestIdPhotos $photos): JsonResponse
    {
        $photos->remove(Guest::findOrFail($id));

        return response()->json(['message' => 'Removed.']);
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
