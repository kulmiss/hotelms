<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The 4 balanced plans (owner, 14 Nov 2026): Free, Starter, Pro (recommended), Business – a flat price per month
 * (price per room 0, the "minimum" is the price), yearly = 2 months free, storage limits. Older plans stay for
 * the hotels that have them (renamed "… (classic)", no longer offered). Features per plan are plan-level switches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('max_storage_mb')->nullable()->after('max_staff');
            $table->boolean('is_public')->default(false)->after('is_active'); // offered on the landing and sign-up pages
            $table->boolean('is_recommended')->default(false)->after('is_public');
        });

        $now = now();
        foreach (['basic', 'standard', 'pro', 'custom'] as $code) {
            $name = DB::table('plans')->where('code', $code)->value('name');
            if ($name && ! str_ends_with($name, '(classic)')) {
                DB::table('plans')->where('code', $code)->update(['name' => $name.' (classic)', 'is_public' => false, 'updated_at' => $now]);
            }
        }

        $all = ['housekeeping', 'cashier_close', 'shifts', 'attendance'];
        $plans = [
            // code, name, description, price, rooms, branches, staff, storage MB, modules, sort, recommended
            ['free', 'Free', 'A small guesthouse runs fully on it', '0', 15, 1, 5, 1024, ['housekeeping'], 11, false],
            ['starter', 'Starter', 'Growing hotels', '1500', 40, 1, 15, 5120, ['housekeeping', 'cashier_close'], 12, false],
            ['professional', 'Pro', 'Busy hotels and two properties', '3500', 100, 2, 40, 20480, $all, 13, true],
            ['business', 'Business', 'Chains and large hotels', '7500', null, null, null, 102400, $all, 14, false],
        ];
        // Off on these plans (on by default everywhere else).
        $off = [
            'free' => ['feature.telegram', 'feature.multi_currency', 'feature.custom_reports', 'feature.full_reports', 'feature.accountant_role', 'feature.branding', 'feature.priority_support'],
            'starter' => ['feature.custom_reports', 'feature.accountant_role', 'feature.branding', 'feature.priority_support'],
            'professional' => [],
            'business' => [],
        ];
        foreach ($plans as [$code, $name, $desc, $price, $rooms, $branches, $staff, $storage, $modules, $sort, $recommended]) {
            if (DB::table('plans')->where('code', $code)->exists()) {
                continue;
            }
            $id = DB::table('plans')->insertGetId([
                'code' => $code, 'name' => $name, 'description' => $desc, 'price_per_room' => '0', 'minimum_monthly' => $price,
                'yearly_free_months' => 2, 'is_custom' => false, 'currency' => 'ETB', 'max_rooms' => $rooms, 'max_branches' => $branches,
                'max_staff' => $staff, 'max_storage_mb' => $storage, 'modules' => json_encode($modules), 'is_active' => true,
                'is_public' => true, 'is_recommended' => $recommended, 'sort' => $sort, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($off[$code] as $key) {
                DB::table('feature_switches')->insert(['key' => $key, 'level' => 'plan', 'target_id' => $id, 'value' => json_encode(false), 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        // Upgrade contact (owner, 14 Nov 2026): editable in Super Admin → Settings.
        if (! DB::table('platform_settings')->where('key', 'support_email')->whereNotNull('value')->where('value', '!=', 'null')->exists()) {
            DB::table('platform_settings')->updateOrInsert(['key' => 'support_email'], ['value' => json_encode('info@kulmiss.com'), 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('plans')->whereIn('code', ['free', 'starter', 'professional', 'business'])->pluck('id');
        if (DB::table('hotels')->whereIn('plan_id', $ids)->exists()) {
            throw new RuntimeException('Hotels use the new plans; move them to another plan first.');
        }
        DB::table('feature_switches')->where('level', 'plan')->whereIn('target_id', $ids)->delete();
        DB::table('plans')->whereIn('id', $ids)->delete();
        DB::table('plans')->where('name', 'like', '% (classic)')->get(['id', 'name'])
            ->each(fn ($p) => DB::table('plans')->where('id', $p->id)->update(['name' => substr($p->name, 0, -10)]));
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn(['max_storage_mb', 'is_public', 'is_recommended']));
    }
};
