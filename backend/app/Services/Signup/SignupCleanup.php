<?php

namespace App\Services\Signup;

use App\Models\Hotel;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\HotelClosing;
use Illuminate\Support\Facades\DB;

/**
 * Daily (owner, 14 Nov 2026):
 * - sign-ups whose email was not confirmed within 7 days are removed (they never signed in, so they hold no guest data;
 *   one that somehow has guests or bookings is kept and left for the Super Admin);
 * - Free hotels nobody signed in to for 90 days are flagged (`inactive_since`) for the Super Admin. Nothing is deleted.
 */
class SignupCleanup
{
    public const INACTIVE_DAYS = 90;

    public function __construct(private HotelClosing $closing, private ActivityLogger $log) {}

    /** @return array{removed: int, kept: int, flagged: int, unflagged: int} */
    public function run(): array
    {
        $removed = $kept = 0;
        $old = Hotel::where('signup_status', 'unverified')->where('created_at', '<', now()->subDays(SelfSignup::VERIFY_DAYS))->get();
        foreach ($old as $hotel) {
            if (DB::table('guests')->where('hotel_id', $hotel->id)->exists() || DB::table('reservations')->where('hotel_id', $hotel->id)->exists()) {
                $kept++;

                continue; // guest data is never deleted automatically
            }
            $this->log->log('hotel.signup_removed', "Removed the sign-up of {$hotel->name}: email not confirmed in ".SelfSignup::VERIFY_DAYS.' days', null, ['email' => $hotel->email], null);
            $this->closing->purge($hotel);
            $removed++;
        }

        [$flagged, $unflagged] = $this->flagInactive();

        return ['removed' => $removed, 'kept' => $kept, 'flagged' => $flagged, 'unflagged' => $unflagged];
    }

    /** @return array{int, int} */
    private function flagInactive(): array
    {
        $since = now()->subDays(self::INACTIVE_DAYS);
        $lastSignIn = User::query()->selectRaw('hotel_id, max(last_login_at) as last')->whereNotNull('hotel_id')->groupBy('hotel_id')->pluck('last', 'hotel_id');
        $flagged = $unflagged = 0;
        Hotel::where('is_demo', false)->with('plan')->where(fn ($q) => $q->whereNull('signup_status')->orWhere('signup_status', 'approved'))
            ->where('created_at', '<', $since)->chunkById(200, function ($hotels) use ($lastSignIn, $since, &$flagged, &$unflagged) {
                foreach ($hotels as $hotel) {
                    $last = $lastSignIn[$hotel->id] ?? null;
                    $idle = $hotel->plan?->isFree() && ($last === null || $last < $since->toDateTimeString());
                    if ($idle && $hotel->inactive_since === null) {
                        $hotel->forceFill(['inactive_since' => $last ?? $hotel->created_at])->save();
                        $flagged++;
                    } elseif (! $idle && $hotel->inactive_since !== null) {
                        $hotel->forceFill(['inactive_since' => null])->save();
                        $unflagged++;
                    }
                }
            });

        return [$flagged, $unflagged];
    }
}
