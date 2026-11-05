<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class MeController extends Controller
{
    /**
     * Everything the app needs to know about the signed-in user.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $hotel = $user->hotel;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'is_super_admin' => $user->is_super_admin,
                'must_change_password' => $user->must_change_password,
            ],
            'hotel' => $hotel ? [
                'id' => $hotel->id,
                'name' => $hotel->name,
                'status' => $hotel->status,
                'default_currency' => $hotel->default_currency,
                'timezone' => $hotel->timezone,
            ] : null,
            'branches' => $user->accessibleBranches()
                ->map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->name])
                ->values(),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is not correct.']);
        }

        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Choose a password different from the current one.']);
        }

        $user->forceFill([
            'password' => $data['password'],
            'must_change_password' => false,
        ])->save();

        return response()->json(['message' => 'Password changed.']);
    }
}
