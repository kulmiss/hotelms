<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password as Broker;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * "Forgot password?": a link by email (60 minutes, once). The answer is always the same,
 * so nobody can find out which emails have an account. Two-step sign-in stays on.
 */
class PasswordResetController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $user = User::where('email', $data['email'])->first();

        if ($user && $user->isActive() && ! $user->hotel?->is_demo) {
            $token = Broker::broker()->createToken($user);
            $link = rtrim((string) config('app.frontend_url'), '/').'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]);
            try {
                Mail::to($user->email)->send(new PasswordResetMail($user->name, $link));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json(['message' => 'If this email has an account, a link is on its way.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $status = Broker::broker()->reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'must_change_password' => false, 'remember_token' => null])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            UserDevice::where('user_id', $user->id)->where('status', 'active')->update(['status' => 'signed_out']);
            if ($user->hotel_id) {
                app(\App\Support\Tenancy\TenantContext::class)->runAs($user->hotel, fn () => app(ActivityLogger::class)->log('security.password_reset', "{$user->name} set a new password with an email link", $user));
            }
        });

        if ($status !== Broker::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'This link is not valid any more. Ask for a new one.']);
        }

        return response()->json(['message' => 'Password changed. You can sign in now.']);
    }
}
