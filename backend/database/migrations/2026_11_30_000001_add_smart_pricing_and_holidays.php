<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seasons and pricing (owner, 14 Nov 2026):
 * - rate rules for holidays, long stays, early-bird and last-minute bookings, and a minimum stay per season;
 * - the hotel's holiday list (Ethiopian holidays preset, editable);
 * - "Change the price by hand" permission (Hotel Admin + Branch Manager), every change logged;
 * - long-stay / early-bird / last-minute / minimum stay are not on the Free plan (switch feature.smart_pricing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_rules', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_nights')->nullable()->after('guest_type_id'); // long_stay: from this many nights
            $table->unsignedSmallInteger('days_before')->nullable()->after('min_nights'); // early_bird: at least / last_minute: at most, days before arrival
            $table->unsignedSmallInteger('min_stay')->nullable()->after('days_before'); // season / holiday: shortest stay that touches it
        });

        Schema::table('reservation_rooms', function (Blueprint $table) {
            $table->decimal('price_override', 14, 2)->nullable()->after('total_amount'); // a price per night set by hand (logged)
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->date('date');
            $table->string('kind', 12)->default('own'); // fixed, calculated (Fasika), expected (moon: check the announcement), own
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['hotel_id', 'date', 'name']);
            $table->index(['hotel_id', 'date']);
        });

        foreach (DB::table('roles')->where('key', 'branch_manager')->where('is_default', true)->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission' => 'reservations.override_price']);
        }

        $free = DB::table('plans')->where('code', 'free')->value('id');
        if ($free && ! DB::table('feature_switches')->where(['key' => 'feature.smart_pricing', 'level' => 'plan', 'target_id' => $free])->exists()) {
            DB::table('feature_switches')->insert(['key' => 'feature.smart_pricing', 'level' => 'plan', 'target_id' => $free, 'value' => json_encode(false), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('feature_switches')->where('key', 'feature.smart_pricing')->delete();
        DB::table('role_permissions')->where('permission', 'reservations.override_price')->delete();
        Schema::dropIfExists('holidays');
        Schema::table('reservation_rooms', fn (Blueprint $table) => $table->dropColumn('price_override'));
        Schema::table('rate_rules', fn (Blueprint $table) => $table->dropColumn(['min_nights', 'days_before', 'min_stay']));
    }
};
