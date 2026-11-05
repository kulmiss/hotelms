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
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
                'is_hotel_admin' => $user->isHotelAdmin(),
                'max_discount_percent' => $user->role->max_discount_percent,
            ] : null,
            'permissions' => $user->permissionKeys(),
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

    /** Edit your own profile (email is the sign-in name, so it is changed by an admin). */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $request->user()->update($data);

        return $this->show($request);
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
