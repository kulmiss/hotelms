<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Signup\SelfSignup;
use App\Support\PasswordRules;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

/**
 * Public hotel sign-up (owner, 14 Nov 2026). Bot protection without cookies or a captcha:
 * a hidden field people never fill (honeypot), a signed form time (too fast = a bot), and a rate limit per address.
 */
class SignupController extends Controller
{
    /** Seconds a person needs at least to fill the form; how long a form stays valid. */
    public const MIN_SECONDS = 4;

    public const MAX_SECONDS = 2 * 3600;

    public function __construct(private SelfSignup $signup) {}

    /** What the sign-up page needs: is it open, and a form time stamp. */
    public function form(): JsonResponse
    {
        return response()->json(['data' => [
            'mode' => SelfSignup::mode(),
            'form_token' => Crypt::encryptString((string) now()->timestamp),
            'trial' => (bool) PlatformSetting::read('signup_trial'),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        if (SelfSignup::mode() === 'off') {
            return response()->json(['message' => 'Sign-up is closed right now. Please contact us.', 'code' => 'signup_off'], 403);
        }
        $this->checkForm($request);
        $email = mb_strtolower(trim((string) $request->input('email')));
        $data = $request->validate([
            'hotel_name' => ['required', 'string', 'min:2', 'max:120'],
            'owner_name' => ['required', 'string', 'min:2', 'max:80'],
            'email' => ['required', 'email:rfc', 'max:120'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9 ]{7,20}$/'],
            'city' => ['required', 'string', 'min:2', 'max:80'],
            'rooms' => ['required', 'integer', 'between:1,2000'],
            'password' => ['required', 'string', 'max:200', ...PasswordRules::forNew($email)],
            'accept_terms' => ['accepted'],
        ], ['accept_terms.accepted' => 'Please accept the Terms and the Privacy policy.', 'phone.regex' => 'Write the phone number with digits, e.g. +251 911 234 567.']);

        $answer = response()->json(['message' => "We sent a link to {$email}. Open it to confirm your email, then sign in.", 'mode' => SelfSignup::mode()], 201);
        // The same answer when the email already has an account, so the form never tells who uses HotelMS.
        if (User::withTrashed()->where('email', $email)->exists()) {
            return $answer;
        }
        $this->signup->register($data, $request);

        return $answer;
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:48']]);
        $state = $this->signup->verify($data['token'], $request);

        return response()->json(['state' => $state, 'message' => $state === 'approved'
            ? 'Your email is confirmed. You can sign in now.'
            : 'Your email is confirmed. We will check your hotel and email you, usually within one working day.']);
    }

    public function resend(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $this->signup->resend($data['email']);

        return response()->json(['message' => 'If this email is waiting for confirmation, we sent a new link.']);
    }

    private function checkForm(Request $request): void
    {
        // Honeypot: a field hidden from people. Bots fill it; they get the normal answer and nothing is made.
        if (filled($request->input('website'))) {
            throw new HttpResponseException(response()->json(['message' => 'We sent a link to your email. Open it to confirm your email, then sign in.', 'mode' => SelfSignup::mode()], 201));
        }
        try {
            $started = (int) Crypt::decryptString((string) $request->input('form_token'));
        } catch (DecryptException) {
            throw ValidationException::withMessages(['form' => 'Please reload the page and try again.']);
        }
        $age = now()->timestamp - $started;
        if ($age < self::MIN_SECONDS) {
            throw ValidationException::withMessages(['form' => 'That was very fast. Please check the form, then send it again.']);
        }
        if ($age > self::MAX_SECONDS) {
            throw ValidationException::withMessages(['form' => 'This form is too old. Please reload the page and try again.']);
        }
    }
}
