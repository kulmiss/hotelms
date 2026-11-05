<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A hotel that leaves (decisions.md W7): its people can no longer sign in, the data is kept
 * 90 days (it can still be reopened), then every row of that hotel is deleted for good.
 */
class HotelClosing
{
    public const KEEP_DAYS = 90;

    public function close(Hotel $hotel, string $reason): void
    {
        DB::transaction(function () use ($hotel, $reason) {
            User::where('hotel_id', $hotel->id)->where('status', 'active')->update(['status' => 'disabled', 'remember_token' => null]);
            DB::table('sessions')->whereIn('user_id', User::where('hotel_id', $hotel->id)->select('id'))->delete();
            DB::table('user_devices')->where('hotel_id', $hotel->id)->update(['status' => 'signed_out']);
            $hotel->forceFill(['suspended_at' => $hotel->suspended_at ?? now(), 'suspend_reason' => 'Closed: '.$reason])->save();
            $hotel->delete(); // soft delete: kept for KEEP_DAYS
        });
    }

    /** Reopen within the 90 days: the Hotel Admins can sign in again and switch their staff back on. */
    public function reopen(Hotel $hotel): void
    {
        DB::transaction(function () use ($hotel) {
            $hotel->restore();
            User::where('hotel_id', $hotel->id)->whereHas('role', fn ($q) => $q->where('key', 'hotel_admin'))->update(['status' => 'active']);
        });
    }

    /** Deletes closed hotels older than 90 days. @return int how many */
    public function purgeExpired(): int
    {
        $hotels = Hotel::onlyTrashed()->where('deleted_at', '<', now()->subDays(self::KEEP_DAYS))->get();
        foreach ($hotels as $hotel) {
            $this->purge($hotel);
        }

        return $hotels->count();
    }

    /** Every row that belongs to this hotel, children first; other hotels are never touched. */
    public function purge(Hotel $hotel): void
    {
        $id = $hotel->id;
        $logo = $hotel->getAttribute('logo_path');
        $hotelTables = collect(DB::select('SELECT TABLE_NAME AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = ?', ['hotel_id']))
            ->pluck('t')->reject(fn ($t) => $t === 'hotels')->unique()->values();

        DB::transaction(function () use ($id, $hotelTables) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            try {
                $users = DB::table('users')->where('hotel_id', $id)->pluck('id');
                $emails = DB::table('users')->where('hotel_id', $id)->pluck('email');
                // Child rows without a hotel_id of their own.
                DB::table('role_permissions')->whereIn('role_id', DB::table('roles')->where('hotel_id', $id)->select('id'))->delete();
                DB::table('reservation_room_rates')->whereIn('reservation_room_id', DB::table('reservation_rooms')->where('hotel_id', $id)->select('id'))->delete();
                DB::table('reservation_guests')->whereIn('reservation_room_id', DB::table('reservation_rooms')->where('hotel_id', $id)->select('id'))->delete();
                DB::table('invoice_item_taxes')->whereIn('invoice_item_id', DB::table('invoice_items')->where('hotel_id', $id)->select('id'))->delete();
                DB::table('cashier_session_balances')->whereIn('cashier_session_id', DB::table('cashier_sessions')->where('hotel_id', $id)->select('id'))->delete();
                DB::table('branch_user')->whereIn('user_id', $users)->delete();
                DB::table('sessions')->whereIn('user_id', $users)->delete();
                DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $users)->delete();
                DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();
                foreach ($hotelTables as $table) {
                    DB::table($table)->where('hotel_id', $id)->delete();
                }
                DB::table('hotels')->where('id', $id)->delete();
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        });

        if ($logo) {
            Storage::disk('public')->delete($logo);
        }
    }
}
