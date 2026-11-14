<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\PlatformSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Works out a hotel's status from its dates (decisions.md → Billing):
 * - manually suspended by the Super Admin → suspended
 * - paid until today or later → active
 * - on a free plan (no trial running) → active
 * - in its free trial → trial
 * - unpaid for up to the grace days (default 3) → grace
 * - after that → suspended (read-only: sign in, view, export; nothing new)
 */
class HotelStatus
{
    public function compute(Hotel $hotel, ?CarbonInterface $today = null): string
    {
        $today = Carbon::instance($today ?? now())->startOfDay();

        if ($hotel->suspended_at !== null) {
            return 'suspended';
        }

        if ($hotel->subscription_ends_at && $hotel->subscription_ends_at->gte($today)) {
            return 'active';
        }

        // A free plan has nothing to pay, so it never runs out (after any trial of a bigger plan).
        if ($hotel->plan?->isFree() && ! ($hotel->trial_ends_at && $hotel->trial_ends_at->gte($today))) {
            return 'active';
        }

        if ($hotel->trial_ends_at && $hotel->trial_ends_at->gte($today)) {
            return 'trial';
        }

        $lastCovered = collect([$hotel->subscription_ends_at, $hotel->trial_ends_at])->filter()->max();

        if ($lastCovered === null) {
            return 'suspended';
        }

        $graceDays = (int) PlatformSetting::read('grace_days');

        return $today->lte($lastCovered->copy()->addDays($graceDays)) ? 'grace' : 'suspended';
    }

    /** Saves the new status if it changed. Returns true when it changed. */
    public function refresh(Hotel $hotel, ?CarbonInterface $today = null): bool
    {
        $status = $this->compute($hotel, $today);

        if ($status === $hotel->status) {
            return false;
        }

        $hotel->forceFill(['status' => $status])->save();

        return true;
    }
}
