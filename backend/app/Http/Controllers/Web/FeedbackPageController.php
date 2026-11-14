<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\GuestFeedback;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Services\Feedback\FeedbackLinks;
use App\Services\HotelSettings;
use App\Services\Landing\LandingContent;
use App\Support\GuestThrottleKey;
use App\Support\PublicLocale;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * The guest's feedback page /f/{secret} (owner, 14 Nov 2026): stars and a comment, no account, no cookies, no script.
 * One answer per stay; the link works 60 days. The page says what happens with the answer.
 */
class FeedbackPageController extends Controller
{
    public function __construct(private FeedbackLinks $links, private TenantContext $tenant, private LandingContent $content) {}

    public function show(Request $request, string $secret): BaseResponse
    {
        [$f, $hotel] = $this->load($secret);
        $this->locale($request, $hotel, $f);

        return $this->render($hotel, $f, $secret, $request->query('sent') === '1', [], []);
    }

    public function store(Request $request, string $secret): BaseResponse
    {
        [$f, $hotel] = $this->load($secret);
        $lang = $this->locale($request, $hotel, $f);
        if ($f->submitted_at || $f->expires_at->isPast()) {
            return redirect("/f/{$secret}?sent=1&lang={$lang}", 303); // one answer per stay: nothing is changed
        }
        $old = $request->only([...GuestFeedback::RATINGS, 'comment', 'public_ok']);
        $key = GuestThrottleKey::for('feedback', $request);
        if (RateLimiter::tooManyAttempts($key, 20)) {
            return $this->render($hotel, $f, $secret, false, ['form' => __('Too many tries from this network. Please try again later.')], $old, 429);
        }
        RateLimiter::hit($key, 3600);
        $stars = ['nullable', 'integer', 'between:1,5'];
        $v = Validator::make($request->all(), ['overall' => ['required', 'integer', 'between:1,5'], 'cleanliness' => $stars, 'staff' => $stars, 'comfort' => $stars, 'value' => $stars, 'comment' => ['nullable', 'string', 'max:2000'], 'public_ok' => ['nullable', 'in:1']],
            ['overall.required' => __('Please choose the stars for your stay overall.')]);
        if ($v->fails()) {
            return $this->render($hotel, $f, $secret, false, collect($v->errors()->toArray())->map(fn ($m) => $m[0])->all(), $old, 422);
        }
        try {
            $this->tenant->runAs($hotel, fn () => $this->links->submit($hotel, $f, $v->validated(), $lang));
        } catch (ValidationException $e) {
            return $this->render($hotel, $f, $secret, false, collect($e->errors())->map(fn ($m) => $m[0])->all(), $old, 422);
        }

        return redirect("/f/{$secret}?sent=1&lang={$lang}", 303);
    }

    /** @return array{0: GuestFeedback, 1: Hotel} */
    private function load(string $secret): array
    {
        $f = $this->links->find($secret);
        $hotel = $f ? Hotel::find($f->hotel_id) : null;
        abort_unless($f && $hotel && $hotel->status !== 'suspended', 404);

        return [$f, $hotel];
    }

    private function locale(Request $request, Hotel $hotel, GuestFeedback $f): string
    {
        $default = $this->tenant->runAs($hotel, function () use ($f) {
            $guestLang = Reservation::with('guest:id,language')->find($f->reservation_id)?->guest?->language;

            return $guestLang ?: (string) app(HotelSettings::class)->get('language', 'en');
        });

        return PublicLocale::apply($request, $default);
    }

    /** @param array<string, string> $errors @param array<string, mixed> $old */
    private function render(Hotel $hotel, GuestFeedback $f, string $secret, bool $sent, array $errors, array $old, int $status = 200): Response
    {
        // "Thank you" only for an answer that was saved (?sent=1 alone shows nothing).
        $html = view('landing.feedback', [
            'c' => $this->content->page(), 'url' => rtrim((string) config('app.url'), '/'), 'hotel' => $hotel, 'f' => $f, 'secret' => $secret,
            'sent' => $f->submitted_at !== null, 'expired' => ! $f->submitted_at && $f->expires_at->isPast(), 'errors' => $errors, 'old' => $old,
        ]);

        return response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Security-Policy' => BookingPageController::CSP, 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }
}
