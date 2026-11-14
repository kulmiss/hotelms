<?php

namespace App\Services;

use App\Models\BookingRequest;
use App\Models\Branch;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Automation\Automations;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
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
        $this->settings->put([self::ENABLED => (bool) $data['enabled'], self::NOTE => $data['note'] ?? null]);
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
            ];
        });
    }

    /** A guest's request. @param array<string, mixed> $data */
    public function request(Hotel $hotel, array $data, Request $request): BookingRequest
    {
        return $this->tenant->runAs($hotel, function () use ($hotel, $data, $request) {
            $branch = Branch::find($data['branch_id']);
            $type = isset($data['room_type_id']) ? RoomType::where('branch_id', $branch?->id)->find($data['room_type_id']) : null;
            if (! $branch || (isset($data['room_type_id']) && ! $type)) {
                throw ValidationException::withMessages(['room_type_id' => 'Choose a room from the list.']);
            }
            $r = BookingRequest::create([
                'branch_id' => $branch->id, 'room_type_id' => $type?->id, 'check_in' => $data['check_in'], 'check_out' => $data['check_out'],
                'adults' => (int) $data['adults'], 'children' => (int) ($data['children'] ?? 0), 'name' => $data['name'], 'phone' => $data['phone'],
                'email' => $data['email'] ?? null, 'note' => $data['note'] ?? null, 'ip' => $request->ip(),
            ]);
            $staff = User::where('hotel_id', $hotel->id)->where('status', 'active')->get()->filter(fn (User $u) => $u->hasPermission('reservations.create'));
            app(Automations::class)->tell($hotel, 'booking_request', $staff, "Booking request from {$r->name}",
                $r->check_in->format('d M').' – '.$r->check_out->format('d M').($type ? " · {$type->name}" : '')." · {$r->phone}", '/booking-requests');

            return $r;
        });
    }
}
