<?php

namespace App\Services;

use App\Mail\HotelAdminWelcome;
use App\Models\Branch;
use App\Models\Hotel;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates a new customer hotel with its first branch and its Hotel Admin
 * (temporary password, must be changed at first sign-in).
 */
class HotelOnboarding
{
    public function __construct(private HotelStatus $status) {}

    /**
     * @param  array<string, mixed>  $hotelData
     * @param  array{name: string, email: string, phone?: string|null}  $adminData
     * @return array{hotel: Hotel, admin: User, temporary_password: string, email_sent: bool}
     */
    public function create(array $hotelData, array $adminData, ?int $trialDays = null): array
    {
        $trialDays ??= (int) PlatformSetting::read('default_trial_days');
        $trialPlanId = $hotelData['trial_plan_id'] ?? PlatformSetting::read('default_trial_plan_id');
        $password = Str::password(12, symbols: false);

        [$hotel, $admin] = DB::transaction(function () use ($hotelData, $adminData, $trialDays, $trialPlanId, $password) {
            $hotel = Hotel::create([
                ...$hotelData,
                'trial_plan_id' => $trialPlanId ?: null,
                'trial_ends_at' => $trialDays > 0 ? now()->addDays($trialDays)->toDateString() : null,
                'status' => 'trial',
            ]);
            $this->status->refresh($hotel);

            $branch = Branch::create([
                'hotel_id' => $hotel->id,
                'name' => 'Main branch',
                'city' => $hotel->city,
                'phone' => $hotel->phone,
                'email' => $hotel->email,
            ]);

            $admin = User::create([
                'hotel_id' => $hotel->id,
                'name' => $adminData['name'],
                'email' => $adminData['email'],
                'phone' => $adminData['phone'] ?? null,
                'password' => $password,
                'status' => 'active',
                'must_change_password' => true,
            ]);
            $admin->branches()->attach($branch);

            return [$hotel, $admin];
        });

        return [
            'hotel' => $hotel->fresh(),
            'admin' => $admin,
            'temporary_password' => $password,
            'email_sent' => $this->sendWelcome($admin, $hotel, $password),
        ];
    }

    private function sendWelcome(User $admin, Hotel $hotel, string $password): bool
    {
        try {
            Mail::to($admin->email)->send(new HotelAdminWelcome($admin, $hotel, $password));

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
