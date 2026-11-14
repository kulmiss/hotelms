<?php

namespace App\Services;

use App\Models\Hotel;
use App\Support\PlanLimits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * File storage per hotel (plan limit "storage", in MB): payment proofs, guest ID photos and the hotel logo.
 * Over the limit, new uploads are refused with a friendly message; files already kept are never removed.
 */
class StorageQuota
{
    public function usedBytes(Hotel $hotel): int
    {
        $proofs = (int) DB::table('payment_proofs')->where('hotel_id', $hotel->id)->sum('size');
        $ids = (int) DB::table('guest_id_photos')->where('hotel_id', $hotel->id)->sum('size');
        $logo = $hotel->logo_path && Storage::disk('public')->exists($hotel->logo_path) ? (int) Storage::disk('public')->size($hotel->logo_path) : 0;

        return $proofs + $ids + $logo;
    }

    /** @return array{used_mb: float, limit_mb: int|null} */
    public function summary(Hotel $hotel): array
    {
        return ['used_mb' => round($this->usedBytes($hotel) / 1048576, 1), 'limit_mb' => $hotel->limit('storage_mb')];
    }

    /** Refuses an upload of $bytes when it would go over the plan's storage. */
    public function check(Hotel $hotel, int $bytes, string $field = 'file'): void
    {
        $limit = $hotel->limit('storage_mb');
        if ($limit === null) {
            return;
        }
        $used = $this->usedBytes($hotel);
        if ($used + $bytes > $limit * 1048576) {
            throw ValidationException::withMessages([$field => PlanLimits::message(sprintf('Your plan includes %s of files (%s used), so this upload does not fit. Nothing was removed', self::size($limit), self::size((int) ceil($used / 1048576))))]);
        }
    }

    private static function size(int $mb): string
    {
        return $mb >= 1024 ? rtrim(rtrim(number_format($mb / 1024, 1), '0'), '.').' GB' : "{$mb} MB";
    }
}
