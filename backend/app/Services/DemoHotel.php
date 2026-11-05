<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\GuestType;
use App\Models\Hotel;
use App\Models\Plan;
use App\Models\RateRule;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The demo hotel for sales meetings and "Try the demo" (decisions.md W11).
 * Rebuilt from scratch every night so visitors' changes disappear.
 * Each later phase adds its own sample data here (rooms, bookings, …).
 */
class DemoHotel
{
    public const PASSWORD = 'demo1234';

    /** One sign-in per role (the "View as" switch). */
    public const PEOPLE = [
        'hotel_admin' => ['Selam Bekele', 'admin'],
        'branch_manager' => ['Dawit Haile', 'manager'],
        'receptionist' => ['Hana Tesfaye', 'reception'],
        'housekeeping' => ['Mulu Girma', 'housekeeping'],
        'accountant' => ['Yonas Alemu', 'accountant'],
    ];

    public function __construct(private DefaultRoles $roles, private TenantContext $tenant, private HotelDefaults $defaults) {}

    public static function email(string $roleKey): string
    {
        return 'demo.'.self::PEOPLE[$roleKey][1].'@hotelms.demo';
    }

    public function current(): ?Hotel
    {
        return Hotel::where('is_demo', true)->first();
    }

    public function reset(): Hotel
    {
        return DB::transaction(function () {
            // Remove the old demo completely (users first: emails are unique).
            // (Without model events: there is nothing to log about a demo being wiped.)
            Model::withoutEvents(fn () => Hotel::withTrashed()->where('is_demo', true)->get()->each(function (Hotel $old) {
                User::withTrashed()->where('hotel_id', $old->id)->forceDelete();
                $old->forceDelete();
            }));

            $hotel = Hotel::create([
                'name' => 'Sheger Demo Hotel',
                'email' => 'info@hotelms.demo',
                'phone' => '+251 11 000 0000',
                'city' => 'Addis Ababa',
                'address' => 'Bole Road',
                'default_currency' => 'ETB',
                'status' => 'active',
                'is_demo' => true,
                'plan_id' => Plan::where('code', 'pro')->value('id'),
                'subscription_ends_at' => now()->addYears(10)->toDateString(),
            ]);

            $branches = $this->tenant->runAs($hotel, fn () => collect(['Bole branch', 'Piassa branch'])
                ->map(fn ($name) => Branch::create(['hotel_id' => $hotel->id, 'name' => $name, 'city' => 'Addis Ababa'])));

            $roles = $this->roles->ensureFor($hotel);
            $this->defaults->apply($hotel);

            foreach (self::PEOPLE as $key => [$name]) {
                $user = User::create([
                    'hotel_id' => $hotel->id,
                    'role_id' => $roles[$key]->id,
                    'name' => $name,
                    'email' => self::email($key),
                    'password' => self::PASSWORD,
                    'status' => 'active',
                    'must_change_password' => false,
                ]);
                // Manager and staff work in Bole; the Hotel Admin sees all branches anyway.
                $user->branches()->attach($key === 'hotel_admin' ? $branches->pluck('id') : [$branches[0]->id]);
            }

            $this->tenant->runAs($hotel, fn () => $this->rooms($branches->all()));

            return $hotel;
        });
    }

    /**
     * 60 sample rooms: Bole has 4 floors × 10 (Standard, Deluxe, Suite),
     * Piassa 2 floors × 10 (Standard, Deluxe). Plus a weekend price and guest types.
     *
     * @param  list<Branch>  $branches
     */
    private function rooms(array $branches): void
    {
        $types = [
            ['Standard', 'Double bed, shower, Wi-Fi', 2, 2, '2500.00', '1500.00', '400.00', ['Wi-Fi', 'TV', 'Shower']],
            ['Deluxe', 'King bed, balcony, breakfast', 2, 3, '3800.00', '2200.00', null, ['Wi-Fi', 'TV', 'Balcony', 'Breakfast']],
            ['Suite', 'Living room, bathtub, city view', 2, 4, '6500.00', null, null, ['Wi-Fi', 'TV', 'Bathtub', 'Mini bar', 'Breakfast']],
        ];
        $layout = [[4, ['Standard' => 6, 'Deluxe' => 3, 'Suite' => 1]], [2, ['Standard' => 7, 'Deluxe' => 3]]];

        foreach ($branches as $i => $branch) {
            [$floors, $mix] = $layout[$i] ?? $layout[1];
            $made = [];
            foreach ($types as [$name, $desc, $base, $max, $night, $day, $hour, $amenities]) {
                if (isset($mix[$name])) {
                    $made[$name] = RoomType::create([
                        'branch_id' => $branch->id, 'name' => $name, 'description' => $desc,
                        'base_capacity' => $base, 'max_capacity' => $max, 'nightly_price' => $night,
                        'day_use_price' => $day, 'hourly_price' => $hour, 'currency' => 'ETB', 'amenities' => $amenities,
                    ]);
                }
            }
            for ($floor = 1; $floor <= $floors; $floor++) {
                $n = 1;
                foreach ($mix as $name => $count) {
                    for ($k = 0; $k < $count; $k++, $n++) {
                        Room::create([
                            'branch_id' => $branch->id, 'room_type_id' => $made[$name]->id,
                            'number' => $floor.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'floor' => (string) $floor, 'status' => 'available',
                        ]);
                    }
                }
            }
        }

        $guestTypes = [];
        foreach (['Walk-in', 'Corporate', 'Government', 'NGO'] as $name) {
            $guestTypes[$name] = GuestType::create(['name' => $name]);
        }
        RateRule::create(['name' => 'Weekend', 'rule_type' => 'weekend', 'days_of_week' => [5, 6], 'adjustment' => 'percent', 'value' => '10.00', 'priority' => 10, 'is_active' => true]);
        RateRule::create(['name' => 'Corporate discount', 'rule_type' => 'guest_type', 'guest_type_id' => $guestTypes['Corporate']->id, 'adjustment' => 'percent', 'value' => '-15.00', 'priority' => 20, 'is_active' => true]);
    }
}
