<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\User;
use App\Services\Signup\SelfSignup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Super Admin → Pending hotels: hotels that registered themselves, approve or refuse with a reason. */
class SignupController extends Controller
{
    public function __construct(private SelfSignup $signup) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:pending,unverified,approved,rejected']]);
        $status = $data['status'] ?? 'pending';
        $hotels = Hotel::where('signup_status', $status)->latest('id')->limit(200)->get();
        $owners = User::whereIn('hotel_id', $hotels->pluck('id'))->orderBy('id')->get()->groupBy('hotel_id')->map->first();

        return response()->json([
            'data' => $hotels->map(fn (Hotel $h) => $this->present($h, $owners[$h->id] ?? null))->values(),
            'counts' => Hotel::whereNotNull('signup_status')->selectRaw('signup_status, count(*) as n')->groupBy('signup_status')->pluck('n', 'signup_status'),
            'mode' => SelfSignup::mode(),
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $hotel = $this->signup->approve(Hotel::findOrFail($id), $request->user());

        return response()->json(['data' => $this->present($hotel, null), 'message' => "{$hotel->name} is approved. We emailed the owner."]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $hotel = $this->signup->reject(Hotel::findOrFail($id), $request->user(), $data['reason']);

        return response()->json(['data' => $this->present($hotel, null), 'message' => "{$hotel->name} is refused. We emailed the owner."]);
    }

    /** @return array<string, mixed> */
    private function present(Hotel $h, ?User $owner): array
    {
        return [
            'id' => $h->id, 'name' => $h->name, 'city' => $h->city, 'phone' => $h->phone, 'email' => $h->email,
            'owner_name' => $h->signup['owner_name'] ?? $owner?->name, 'rooms' => $h->signup['rooms'] ?? null,
            'signup_status' => $h->signup_status, 'created_at' => $h->created_at?->toIso8601String(),
            'email_confirmed' => $owner ? $owner->email_verified_at !== null : null,
            'decided_at' => $h->signup_decided_at?->toIso8601String(), 'reason' => $h->signup_reason,
        ];
    }
}
