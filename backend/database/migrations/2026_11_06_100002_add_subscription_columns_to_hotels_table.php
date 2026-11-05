<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->date('trial_ends_at')->nullable()->after('subscription_ends_at');
            $table->foreignId('trial_plan_id')->nullable()->after('trial_ends_at')->constrained('plans')->nullOnDelete();
            $table->string('billing_cycle')->default('monthly')->after('trial_plan_id');
            $table->decimal('custom_price', 14, 2)->nullable()->after('billing_cycle');
            $table->timestamp('suspended_at')->nullable()->after('custom_price');
            $table->string('suspend_reason')->nullable()->after('suspended_at');
        });

        Schema::table('users', function (Blueprint $table) {
            // Super Admin team role: owner, support, sales, finance, tech.
            $table->string('platform_role')->nullable()->after('is_super_admin');
        });

        DB::table('users')->where('is_super_admin', true)->whereNull('platform_role')->update(['platform_role' => 'owner']);

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('ETB');
            $table->string('method');
            $table->string('reference')->nullable();
            $table->string('billing_cycle')->default('monthly');
            $table->unsignedSmallInteger('months');
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamp('paid_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('platform_settings');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('platform_role');
        });

        Schema::table('hotels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trial_plan_id');
            $table->dropColumn(['trial_ends_at', 'billing_cycle', 'custom_price', 'suspended_at', 'suspend_reason']);
        });
    }
};
