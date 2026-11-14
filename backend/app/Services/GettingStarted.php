<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Services\Security\TwoFactor;

/**
 * The "Getting started" checklist on the Hotel Admin's dashboard (owner, 14 Nov 2026).
 * Each item is known from the hotel's own data; call inside the hotel's tenant context.
 */
class GettingStarted
{
    public const DISMISSED = 'getting_started_dismissed';

    public function __construct(private HotelSettings $settings, private TwoFactor $twoFactor) {}

    /** @return array{items: list<array{key: string, done: bool, link: string}>, done: int, total: int, dismissed: bool} */
    public function for(Hotel $hotel, User $admin): array
    {
        $checks = [
            'setup' => [$hotel->setup_completed_at !== null, '/setup'],
            'rooms' => [Room::exists(), '/settings/rooms'],
            'logo' => [! empty($hotel->logo_path), '/settings/profile'],
            'staff' => [User::where('hotel_id', $hotel->id)->whereKeyNot($admin->id)->exists(), '/staff/new'],
            'booking' => [Reservation::exists(), '/reservations/new'],
            'check_in' => [Reservation::whereIn('status', ['checked_in', 'checked_out'])->exists(), '/rooms'],
            'payment' => [Payment::exists(), '/billing'],
            'two_factor' => [$this->twoFactor->enabled($admin), '/profile'],
        ];
        $items = [];
        foreach ($checks as $key => [$done, $link]) {
            $items[] = ['key' => $key, 'done' => (bool) $done, 'link' => $link];
        }
        $done = count(array_filter($items, fn ($i) => $i['done']));

        return ['items' => $items, 'done' => $done, 'total' => count($items), 'dismissed' => (bool) $this->settings->get(self::DISMISSED, false)];
    }

    public function dismiss(): void
    {
        $this->settings->put([self::DISMISSED => true]);
    }
}
