<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Hotel;
use App\Models\Plan;
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

            return $hotel;
        });
    }
}
