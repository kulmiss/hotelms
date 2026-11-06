<?php

namespace App\Http\Controllers\Api\Hotel;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\HotelSetup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Setup walkthrough for the Hotel Admin. */
class SetupController extends Controller
{
    public function __construct(private HotelSetup $setup) {}

    public function show(Request $request): JsonResponse
    {
        $user = $this->admin($request);
        $hotel = $user->hotel->load(['plan', 'trialPlan']);

        return response()->json(['data' => $this->setup->overview($hotel, $user) + [
            'hotel' => [
                'name' => $hotel->name,
                'status' => $hotel->status,
                'plan' => ($hotel->trialPlan ?? $hotel->plan)?->name,
                'trial_ends_at' => $hotel->trial_ends_at?->toDateString(),
                'max_rooms' => $hotel->limit('rooms'),
            ],
        ]]);
    }

    public function mark(Request $request, string $step): JsonResponse
    {
        $user = $this->admin($request);
        $data = $request->validate(['state' => ['required', Rule::in(['done', 'skipped'])]]);
        $this->setup->mark($user->hotel, $step, $data['state']);

        return response()->json(['data' => $this->setup->overview($user->hotel->refresh(), $user)]);
    }

    public function finish(Request $request): JsonResponse
    {
        $user = $this->admin($request);
        $this->setup->finish($user->hotel, $user);

        return response()->json(['data' => $this->setup->overview($user->hotel->refresh(), $user)]);
    }

    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->hotel_id && $user->isHotelAdmin(), 403, 'Only the Hotel Admin can run the setup.');

        return $user;
    }
}
