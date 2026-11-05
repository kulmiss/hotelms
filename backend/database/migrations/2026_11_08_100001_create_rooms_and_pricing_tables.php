<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Rooms and pricing (database-schema.md section 4). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->unsignedTinyInteger('base_capacity')->default(2);
            $table->unsignedTinyInteger('max_capacity')->default(2);
            $table->decimal('nightly_price', 14, 2);
            $table->decimal('day_use_price', 14, 2)->nullable(); // null = not offered
            $table->decimal('hourly_price', 14, 2)->nullable(); // null = not offered
            $table->string('currency', 3)->default('ETB');
            $table->json('amenities')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'name']); // unique among non-deleted rows: checked in the app (MySQL has no partial indexes)
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            $table->string('number', 20);
            $table->string('floor', 10)->nullable();
            $table->string('status')->default('available'); // available, occupied, dirty, cleaning, maintenance, out_of_order
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'number']); // unique among non-deleted rows: checked in the app
            $table->index(['hotel_id', 'status']);
        });

        Schema::create('guest_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['hotel_id', 'name']);
        });

        Schema::create('rate_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->nullable()->constrained()->cascadeOnDelete(); // null = all room types
            $table->string('name');
            $table->string('rule_type'); // weekend, season, guest_type
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->json('days_of_week')->nullable(); // ISO 1 (Mon) – 7 (Sun)
            $table->foreignId('guest_type_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('adjustment'); // fixed_price, percent, amount
            $table->decimal('value', 14, 2);
            $table->unsignedSmallInteger('priority')->default(10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_rules');
        Schema::dropIfExists('guest_types');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('room_types');
    }
};
