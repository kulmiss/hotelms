<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Phase 6 added report permissions (reports.md): give them to existing default roles. */
return new class extends Migration
{
    private const NEW = [
        'reports.my_shift' => ['branch_manager', 'receptionist', 'accountant'],
        'reports.my_work' => ['housekeeping'],
        'reports.forecast' => ['branch_manager', 'accountant'],
        'reports.custom' => ['branch_manager', 'accountant'],
        'reports.schedule' => ['branch_manager', 'accountant'],
        'reports.accounting_export' => ['accountant'],
    ];

    public function up(): void
    {
        foreach (self::NEW as $permission => $keys) {
            $roles = DB::table('roles')->whereIn('key', $keys)->where('is_default', true)->pluck('id');
            foreach ($roles as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission' => $permission]);
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', array_keys(self::NEW))->delete();
    }
};
