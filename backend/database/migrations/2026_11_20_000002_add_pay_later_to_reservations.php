<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Pay at check-out": staff with the permission may let a guest stay without a deposit or up-front payment
 * (when the hotel allows it in Booking rules). The guest still pays everything before checking out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('pay_later_by')->nullable()->after('checked_out_by')->constrained('users')->nullOnDelete();
            $table->dateTime('pay_later_at')->nullable()->after('pay_later_by');
        });

        $roles = DB::table('roles')->where('key', 'branch_manager')->where('is_default', true)->pluck('id');
        foreach ($roles as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission' => 'payments.pay_later']);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', 'payments.pay_later')->delete();
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pay_later_by');
            $table->dropColumn('pay_later_at');
        });
    }
};
