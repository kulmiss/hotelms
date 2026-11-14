<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\BookingPage;
use App\Services\Landing\LandingContent;
use App\Support\GuestThrottleKey;
use App\Support\PublicLocale;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * A hotel's public booking page /h/{slug}: plain HTML form, no script, no cookies.
 * Bots: hidden field + form time + a limit per address (like the sign-up form).
 */
class BookingPageController extends Controller
{
    public const CSP = "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";

    public function __construct(private BookingPage $pages, private LandingContent $content) {}

    public function show(Request $request, string $slug): BaseResponse
    {
        $hotel = $this->pages->find($slug) ?? abort(404);
        $page = $this->pages->page($hotel);
        PublicLocale::apply($request, $page['language']);
        // "See prices": dates and room only (never personal data in a link).
        $quote = $request->has('check_in') ? $this->pages->quote($hotel, $request->query('room_type_id'), $request->query('check_in'), $request->query('check_out')) : null;
        $old = $quote ? ['check_in' => $request->query('check_in'), 'check_out' => $request->query('check_out'), 'room_type_id' => $request->query('room_type_id')] : [];

        return $this->render($slug, $page, $request->query('sent') === '1', $request->has('check_in') && ! $quote ? ['quote' => __('Choose an arrival from today and a departure after it.')] : [], $old, 200, $quote);
    }

    public function store(Request $request, string $slug): BaseResponse
    {
        $hotel = $this->pages->find($slug) ?? abort(404);
        $page = $this->pages->page($hotel);
        $lang = PublicLocale::apply($request, $page['language']);
        $done = "/h/{$slug}?sent=1&lang={$lang}";
        $old = $request->only(['branch_id', 'room_type_id', 'check_in', 'check_out', 'adults', 'children', 'name', 'phone', 'email', 'note']);

        if (filled($request->input('website'))) {
            return redirect($done, 303); // a bot: the normal answer, nothing saved
        }
        $key = GuestThrottleKey::for('booking-request', $request);
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return $this->render($slug, $page, false, ['form' => __('Too many requests from this network. Please call the hotel.')], $old, 429);
        }
        try {
            $age = now()->timestamp - (int) Crypt::decryptString((string) $request->input('form_token'));
        } catch (DecryptException) {
            $age = -1;
        }
        if ($age < 3 || $age > 2 * 3600) {
            return $this->render($slug, $page, false, ['form' => __('Please check the form and send it again.')], $old, 422);
        }
        $v = Validator::make($request->all(), [
            'branch_id' => ['required', 'integer'],
            'room_type_id' => ['nullable', 'integer'],
            'check_in' => ['required', 'date', 'after_or_equal:today'],
            'check_out' => ['required', 'date', 'after:check_in', 'before_or_equal:'.now()->addYear()->toDateString()],
            'adults' => ['required', 'integer', 'between:1,20'],
            'children' => ['nullable', 'integer', 'between:0,20'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9 ]{7,20}$/'],
            'email' => ['nullable', 'email:rfc', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['phone.regex' => __('Write the phone number with digits, e.g. +251 911 234 567.'), 'check_in.after_or_equal' => __('Choose today or a later day.')]);
        $v->after(function ($v) use ($request) {
            $in = strtotime((string) $request->input('check_in'));
            $out = strtotime((string) $request->input('check_out'));
            if ($in && $out && ($out - $in) / 86400 > BookingPage::MAX_NIGHTS) {
                $v->errors()->add('check_out', __('For more than :n nights, please call the hotel.', ['n' => BookingPage::MAX_NIGHTS]));
            }
        });
        if ($v->fails()) {
            return $this->render($slug, $page, false, collect($v->errors()->toArray())->map(fn ($m) => $m[0])->all(), $old, 422);
        }
        RateLimiter::hit($key, 3600);
        try {
            $this->pages->request($hotel, $v->validated(), $request);
        } catch (ValidationException $e) {
            return $this->render($slug, $page, false, collect($e->errors())->map(fn ($m) => $m[0])->all(), $old, 422);
        }

        return redirect($done, 303);
    }

    /** @param array<string, string> $errors @param array<string, mixed> $old */
    private function render(string $slug, array $page, bool $sent, array $errors, array $old, int $status = 200, ?array $quote = null): Response
    {
        $c = $this->content->page();
        $html = view('landing.booking', [
            'c' => $c, 'url' => rtrim((string) config('app.url'), '/'), 'p' => $page, 'slug' => $slug, 'sent' => $sent,
            'errors' => $errors, 'old' => $old, 'token' => Crypt::encryptString((string) now()->timestamp), 'quote' => $quote,
        ]);

        return response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Security-Policy' => self::CSP, 'Cache-Control' => 'no-store']);
    }
}
