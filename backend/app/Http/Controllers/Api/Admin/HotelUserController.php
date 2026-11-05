<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TemporaryPassword;
use App\Models\Hotel;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class HotelUserController extends Controller
{
    /**
     * Gives a hotel user a new temporary password. Passwords are stored
     * scrambled, so the old one can never be shown; the new one is shown once
     * and can be emailed. Existing sign-ins on other devices are ended.
     */
    public function resetPassword(Request $request, Hotel $hotel, User $user): JsonResponse
    {
        abort_unless($user->hotel_id === $hotel->id, 404);

        $data = $request->validate(['send_email' => ['sometimes', 'boolean']]);
        $password = Str::password(12, symbols: false);

        $user->forceFill([
            'password' => $password,
            'must_change_password' => true,
            'remember_token' => Str::random(60),
        ])->save();
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        $emailSent = false;
        if ($data['send_email'] ?? true) {
            try {
                Mail::to($user->email)->send(new TemporaryPassword($user, $hotel, $password));
                $emailSent = true;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'temporary_password' => $password,
            'email_sent' => $emailSent,
        ]);
    }
}
