<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hotel settings, policies and money (database-schema.md section 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->string('legal_name')->nullable()->after('name');
            $table->string('tin')->nullable()->after('legal_name');
            $table->string('vat_number')->nullable()->after('tin');
        });

        Schema::create('hotel_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['hotel_id', 'key']);
        });

        Schema::create('hotel_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('module');
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
            $table->unique(['hotel_id', 'module']);
        });

        Schema::create('hotel_currencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('code', 3);
            $table->string('symbol', 8);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['hotel_id', 'code']);
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('from_currency', 3);
            $table->string('to_currency', 3);
            $table->decimal('rate', 14, 6);
            $table->date('effective_date');
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['hotel_id', 'from_currency', 'to_currency', 'effective_date'], 'exchange_rates_lookup');
        });

        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('rate', 5, 2);
            $table->string('applies_to')->default('all'); // rooms, extras, all
            $table->boolean('is_inclusive')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type'); // cash, mobile_money, bank_transfer
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('charge_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('default_price', 14, 2)->nullable();
            $table->string('currency', 3)->default('ETB');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cancellation_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('free_until_hours')->nullable(); // null = never free
            $table->string('penalty_type'); // nights, percent, fixed
            $table->decimal('penalty_value', 14, 2);
            $table->boolean('applies_to_no_show')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['cancellation_policies', 'charge_types', 'payment_methods', 'taxes', 'exchange_rates', 'hotel_currencies', 'hotel_modules', 'hotel_settings'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn(['legal_name', 'tin', 'vat_number']);
        });
    }
};
