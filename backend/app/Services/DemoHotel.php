<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Guest;
use App\Models\GuestType;
use App\Models\PaymentMethod;
use App\Models\Hotel;
use App\Models\Plan;
use App\Models\RateRule;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Booking\ReservationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
                // Bookings first: they point at guests, rooms and payment methods.
                foreach (['payments', 'reservations', 'guests'] as $table) {
                    DB::table($table)->where('hotel_id', $old->id)->delete();
                }
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
            $hotel->forceFill(['setup_completed_at' => now()])->save(); // the demo is ready to use

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
            $admin = User::where('email', self::email('hotel_admin'))->first();
            $this->tenant->runAs($hotel, fn () => $this->bookings($admin, $branches->all()));

            return $hotel;
        });
    }

    /**
     * Sample guests and future bookings for the next weeks (past stays come with the front desk).
     *
     * @param  list<Branch>  $branches
     */
    private function bookings(User $admin, array $branches): void
    {
        $first = ['Abebe', 'Almaz', 'Bekele', 'Chaltu', 'Dawit', 'Eden', 'Fikru', 'Genet', 'Hana', 'Kebede', 'Lidya', 'Meseret', 'Nahom', 'Rahel', 'Samuel', 'Selam', 'Tigist', 'Yared', 'Zewdu', 'Meron', 'John', 'Sarah', 'Ahmed', 'Li'];
        $last = ['Tadesse', 'Alemu', 'Bekele', 'Girma', 'Haile', 'Kassa', 'Mengistu', 'Negash', 'Tesfaye', 'Wolde', 'Smith', 'Mohammed', 'Wang'];
        $corporate = GuestType::where('name', 'Corporate')->value('id');
        mt_srand(2026); // the same demo every night

        $guests = [];
        foreach ($first as $i => $name) {
            $foreign = $i >= 20;
            $guests[] = Guest::create([
                'first_name' => $name, 'last_name' => $last[$i % count($last)],
                'phone' => '+251 9'.mt_rand(10, 99).' '.mt_rand(100000, 999999),
                'nationality' => $foreign ? ['American', 'British', 'Sudanese', 'Chinese'][$i - 20] : 'Ethiopian',
                'id_type' => $foreign ? 'passport' : 'national_id',
                'id_number' => ($foreign ? 'P' : 'ET').mt_rand(1000000, 9999999),
                'guest_type_id' => $i % 5 === 0 ? $corporate : null,
            ]);
        }

        $service = app(ReservationService::class);
        $cash = PaymentMethod::where('type', 'cash')->value('id');
        $tz = 'Africa/Addis_Ababa';
        foreach (range(1, 36) as $n) {
            $branch = $branches[$n % 3 === 0 ? 1 : 0];
            $types = RoomType::where('branch_id', $branch->id)->pluck('id')->all();
            $start = now($tz)->addDays(mt_rand(1, 30))->toDateString();
            try {
                $r = $service->create([
                    'branch_id' => $branch->id, 'stay_type' => 'nightly', 'check_in' => $start,
                    'check_out' => now($tz)->parse($start)->addDays(mt_rand(1, 4))->toDateString(),
                    'guest_id' => $guests[array_rand($guests)]->id, 'source' => ['phone', 'email', 'agent', 'phone'][mt_rand(0, 3)],
                    'rooms' => array_map(fn () => ['room_type_id' => $types[array_rand($types)], 'adults' => 2], range(1, mt_rand(1, 10) > 8 ? 2 : 1)),
                ], $admin);
                if ($n % 4 !== 0 && $cash) { // most bookings have their deposit
                    $service->recordPayment($r, ['type' => 'deposit', 'payment_method_id' => $cash, 'amount' => (string) $r->deposit_required], $admin);
                }
            } catch (ValidationException) {
                // fully booked that day: skip
            }
        }
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
