<?php

namespace App\Services;

use App\Models\BookingRequest;
use App\Models\Branch;
use App\Models\GuestFeedback;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Automation\Automations;
use App\Services\Messages\GuestMessenger;
use App\Support\HotelClock;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The hotel's public booking page /h/{slug} (owner, 14 Nov 2026, "2a"). Off until the hotel turns it on.
 * Guests send a request (no account, no cookies); reception books it with the normal form or declines it.
 * Free and Starter show "Powered by"; plans with `feature.branding` don't.
 */
class BookingPage
{
    public const ENABLED = 'booking_page_enabled';

    public const NOTE = 'booking_page_note';

    /** Show guests' stars and the comments they allowed (owner, 14 Nov 2026: only with the guest's permission). */
    public const REVIEWS = 'booking_page_reviews';

    public const MAX_NIGHTS = 60;

    public function __construct(private TenantContext $tenant, private HotelSettings $settings, private Switches $switches) {}

    /** The hotel behind a link, when its page is on and the hotel may work. */
    public function find(string $slug): ?Hotel
    {
        $hotel = Hotel::where('booking_slug', $slug)->first();
        if (! $hotel || $hotel->signupLocked() || $hotel->status === 'suspended') {
            return null;
        }

        return $this->tenant->runAs($hotel, fn () => (bool) $this->settings->get(self::ENABLED, false)) ? $hotel : null;
    }

    /** Settings → Booking page (inside the hotel's tenant context). @return array<string, mixed> */
    public function settingsFor(Hotel $hotel): array
    {
        return [
            'enabled' => (bool) $this->settings->get(self::ENABLED, false),
            'slug' => $hotel->booking_slug,
            'url' => $hotel->booking_slug ? rtrim((string) config('app.url'), '/').'/h/'.$hotel->booking_slug : null,
            'note' => $this->settings->get(self::NOTE),
            'reviews' => (bool) $this->settings->get(self::REVIEWS, false),
            'powered_by' => ! $this->switches->on($hotel, 'feature.branding'),
        ];
    }

    /** @param array{enabled: bool, slug?: string|null, note?: string|null} $data */
    public function save(Hotel $hotel, array $data): void
    {
        $slug = isset($data['slug']) && $data['slug'] !== '' ? Str::slug($data['slug']) : ($hotel->booking_slug ?? Str::slug($hotel->name));
        if ($slug === '' || in_array($slug, ['admin', 'api', 'login', 'register', 'dashboard'], true)) {
            $slug = 'hotel-'.$hotel->id;
        }
        if (Hotel::where('booking_slug', $slug)->whereKeyNot($hotel->id)->exists()) {
            if (isset($data['slug']) && $data['slug'] !== '') {
                throw ValidationException::withMessages(['slug' => 'Another hotel uses this link. Choose another one.']);
            }
            $slug .= '-'.$hotel->id;
        }
        $hotel->forceFill(['booking_slug' => $slug])->save();
        $this->settings->put([self::ENABLED => (bool) $data['enabled'], self::NOTE => $data['note'] ?? null, self::REVIEWS => (bool) ($data['reviews'] ?? false)]);
    }

    /** What the public page shows: branches with their room types and prices. @return array<string, mixed> */
    public function page(Hotel $hotel): array
    {
        return $this->tenant->runAs($hotel, function () use ($hotel) {
            $branches = Branch::orderBy('name')->get(['id', 'name', 'city']);
            $types = RoomType::whereIn('branch_id', $branches->pluck('id'))->orderBy('nightly_price')->get();

            return [
                'hotel' => ['name' => $hotel->name, 'city' => $hotel->city, 'phone' => $hotel->phone, 'email' => $hotel->email, 'logo' => $hotel->logo_path ? '/storage/'.ltrim($hotel->logo_path, '/') : null],
                'branches' => $branches->map(fn (Branch $b) => [
                    'id' => $b->id, 'name' => $b->name, 'city' => $b->city,
                    'types' => $types->where('branch_id', $b->id)->map(fn (RoomType $t) => [
                        'id' => $t->id, 'name' => $t->name, 'description' => $t->description, 'max' => $t->max_capacity,
                        'price' => $t->nightly_price !== null ? number_format((float) $t->nightly_price) : null, 'currency' => $t->currency ?: $hotel->default_currency ?: 'ETB',
                        'amenities' => array_slice((array) ($t->amenities ?? []), 0, 6),
                    ])->values()->all(),
                ])->values()->all(),
                'note' => $this->settings->get(self::NOTE),
                'powered_by' => ! $this->switches->on($hotel, 'feature.branding'),
                'language' => (string) $this->settings->get('language', 'en'), // the hotel's default; ?lang= changes it
                'reviews' => $this->settings->get(self::REVIEWS, false) ? $this->reviews() : null,
                'menu_url' => ((array) $this->settings->get(GuestMessenger::INFO, []))['menu_url'] ?? null, // Settings → Guest messages
            ];
        });
    }

    /**
     * Guest stars: the average of every answer (never only the good ones) and the latest comments the guests allowed,
     * as they wrote them, with the first name only. @return array{count: int, average: ?float, items: list<array<string, mixed>>}
     */
    private function reviews(): array
    {
        $all = GuestFeedback::whereNotNull('submitted_at');
        $items = (clone $all)->with('guest:id,first_name')->where('public_ok', true)->orderByDesc('submitted_at')->limit(5)->get()
            ->map(fn (GuestFeedback $f) => ['name' => $f->guest?->first_name, 'overall' => $f->overall, 'comment' => $f->comment, 'month' => $f->submitted_at->translatedFormat('F Y')])->all();

        return ['count' => (clone $all)->count(), 'average' => ($a = (clone $all)->avg('overall')) !== null ? round((float) $a, 1) : null, 'items' => $items];
    }

    /**
     * The price night by night for a guest who checks dates (no personal data in the link), or null when the
     * dates are not usable. @return array{type: string, currency: string, total: string, nights: list<array{date: string, amount: string, rule: ?string}>, min_stay: ?array{name: string, nights: int}}|null
     */
    public function quote(Hotel $hotel, mixed $typeId, mixed $checkIn, mixed $checkOut): ?array
    {
        if (! is_string($checkIn) || ! is_string($checkOut) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkIn) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkOut)) {
            return null;
        }
        $in = Carbon::parse($checkIn)->startOfDay();
        $out = Carbon::parse($checkOut)->startOfDay();
        $today = HotelClock::now($hotel)->startOfDay();
        if ($in->lt($today) || $out->lte($in) || $in->diffInDays($out) > self::MAX_NIGHTS || $in->gt($today->copy()->addYear())) {
            return null;
        }

        return $this->tenant->runAs($hotel, function () use ($hotel, $typeId, $in, $out, $today) {
            $branches = Branch::pluck('id');
            $type = is_numeric($typeId) ? RoomType::whereIn('branch_id', $branches)->find((int) $typeId) : RoomType::whereIn('branch_id', $branches)->orderBy('nightly_price')->first();
            if (! $type || $type->nightly_price === null) {
                return null;
            }
            $prices = app(PriceCalculator::class);
            $stay = $prices->stay($type, $in, $out, null, $today);

            return [
                'type' => $type->name, 'currency' => $type->currency ?: $hotel->default_currency ?: 'ETB', 'total' => $stay['total'],
                'nights' => array_map(fn ($n) => ['date' => $n['date'], 'amount' => $n['amount'], 'rule' => $n['rule']['name'] ?? null], $stay['nights']),
                'min_stay' => $prices->minStayProblem($type, $in, $out),
            ];
        });
    }

    /** A guest's request. @param array<string, mixed> $data */
    public function request(Hotel $hotel, array $data, Request $request): BookingRequest
    {
        return $this->tenant->runAs($hotel, function () use ($hotel, $data) {
            $branch = Branch::find($data['branch_id']);
            $type = isset($data['room_type_id']) ? RoomType::where('branch_id', $branch?->id)->find($data['room_type_id']) : null;
            if (! $branch || (isset($data['room_type_id']) && ! $type)) {
                throw ValidationException::withMessages(['room_type_id' => __('Choose a room from the list.')]);
            }
            // Minimum stay of a season or holiday (the hotel can still agree to less by phone).
            $short = $type ? app(PriceCalculator::class)->minStayProblem($type, Carbon::parse($data['check_in']), Carbon::parse($data['check_out'])) : null;
            if ($short) {
                throw ValidationException::withMessages(['check_out' => __(':name: stays of at least :n nights. Please call the hotel for a shorter stay.', ['name' => $short['name'], 'n' => $short['nights']])]);
            }
            $r = BookingRequest::create([
                'branch_id' => $branch->id, 'room_type_id' => $type?->id, 'check_in' => $data['check_in'], 'check_out' => $data['check_out'],
                'adults' => (int) $data['adults'], 'children' => (int) ($data['children'] ?? 0), 'name' => $data['name'], 'phone' => $data['phone'],
                'email' => $data['email'] ?? null, 'note' => $data['note'] ?? null,
                'feedback_consent' => ! empty($data['feedback_consent']), 'language' => app()->getLocale(),
            ]);
            app(GuestMessenger::class)->sendForRequest($hotel, $r, 'request_received');
            $staff = User::where('hotel_id', $hotel->id)->where('status', 'active')->get()->filter(fn (User $u) => $u->hasPermission('reservations.create'));
            app(Automations::class)->tell($hotel, 'booking_request', $staff, "Booking request from {$r->name}",
                $r->check_in->format('d M').' – '.$r->check_out->format('d M').($type ? " · {$type->name}" : '')." · {$r->phone}", '/booking-requests');

            return $r;
        });
    }
}
