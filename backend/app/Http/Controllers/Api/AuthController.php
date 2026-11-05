<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Impersonation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Web sign-in (Sanctum cookie session).
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Auth::guard('web')->validate(['email' => $credentials['email'], 'password' => $credentials['password']])) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages(['email' => 'This account is disabled. Please contact your hotel admin.']);
        }

        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json(['message' => 'Signed in.']);
    }

    public function logout(Request $request, Impersonation $impersonation): JsonResponse
    {
        $impersonation->stop($request); // ends a support session properly, if any
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.']);
    }
}
