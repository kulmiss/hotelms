<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plans are priced per room per month with a minimum (claude/plans.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['price', 'billing_cycle']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->string('code')->nullable()->unique()->after('name');
            $table->string('description')->nullable()->after('code');
            $table->decimal('price_per_room', 14, 2)->default(0)->after('description');
            $table->decimal('minimum_monthly', 14, 2)->default(0)->after('price_per_room');
            $table->unsignedTinyInteger('yearly_free_months')->default(2)->after('minimum_monthly');
            $table->boolean('is_custom')->default(false)->after('yearly_free_months');
            $table->unsignedInteger('max_branches')->nullable()->after('max_rooms');
            $table->unsignedInteger('max_staff')->nullable()->after('max_branches');
            $table->unsignedSmallInteger('sort')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['code', 'description', 'price_per_room', 'minimum_monthly', 'yearly_free_months', 'is_custom', 'max_branches', 'max_staff', 'sort']);
            $table->decimal('price', 14, 2)->default(0);
            $table->string('billing_cycle')->default('monthly');
        });
    }
};
