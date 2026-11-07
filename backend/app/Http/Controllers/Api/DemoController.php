<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\HotelResource;
use App\Models\Hotel;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\DemoHotel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class DemoController extends Controller
{
    /** Public "Try the demo": sign in as a demo user of the chosen role. */
    public function login(Request $request, DemoHotel $demo): JsonResponse
    {
        abort_unless((bool) PlatformSetting::read('demo_public'), 404);

        $data = $request->validate(['role' => ['required', Rule::in(array_keys(DemoHotel::PEOPLE))]]);

        $user = User::where('email', DemoHotel::email($data['role']))->first()
            ?? ($demo->reset() ? User::where('email', DemoHotel::email($data['role']))->first() : null);
        abort_unless($user, 404);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        // The visit ends after the minutes the Super Admin set (DemoTimeLimit signs the visitor out).
        $request->session()->put('demo_until', now()->addMinutes(max(5, (int) PlatformSetting::read('demo_minutes')))->getTimestamp());

        return response()->json(['message' => 'Welcome to the demo.']);
    }

    /** Super Admin: rebuild the demo hotel now. */
    public function reset(Hotel $hotel, DemoHotel $demo): JsonResponse
    {
        abort_unless($hotel->is_demo, 404);

        return response()->json(['data' => new HotelResource($demo->reset())]);
    }
}
