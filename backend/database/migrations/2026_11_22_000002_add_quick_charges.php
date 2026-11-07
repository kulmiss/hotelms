<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quick charges (owner, 2026-11-07): charge types shown as one-tap buttons ("Breakfast 250"), the
 * "charges.room" permission and a default "Restaurant / Bar" role that can only charge rooms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charge_types', function (Blueprint $table) {
            $table->boolean('is_quick')->default(false)->after('is_active');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('is_quick');
        });

        foreach (DB::table('roles')->whereIn('key', ['branch_manager', 'receptionist'])->where('is_default', true)->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission' => 'charges.room']);
        }
        // Every hotel gets the new default role (skipped where the hotel already has a role with that name).
        $now = now();
        foreach (DB::table('hotels')->pluck('id') as $hotelId) {
            $taken = DB::table('roles')->where('hotel_id', $hotelId)->where(fn ($q) => $q->where('key', 'restaurant')->orWhere('name', 'Restaurant / Bar'))->exists();
            if ($taken) {
                continue;
            }
            $roleId = DB::table('roles')->insertGetId([
                'hotel_id' => $hotelId, 'key' => 'restaurant', 'name' => 'Restaurant / Bar', 'is_default' => true, 'is_locked' => false,
                'max_discount_percent' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach (['charges.room', 'shifts.view_own', 'attendance.clock'] as $permission) {
                DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission' => $permission]);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', 'charges.room')->delete();
        $roles = DB::table('roles')->where('key', 'restaurant')->where('is_default', true)->pluck('id');
        DB::table('role_permissions')->whereIn('role_id', $roles)->delete();
        DB::table('roles')->whereIn('id', $roles)->whereNotIn('id', DB::table('users')->whereNotNull('role_id')->select('role_id'))->delete();
        Schema::table('charge_types', function (Blueprint $table) {
            $table->dropColumn(['is_quick', 'sort_order']);
        });
    }
};
