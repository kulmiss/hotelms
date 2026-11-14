<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public booking page (owner, 14 Nov 2026, "2a"): guests send a booking request on /h/{slug};
 * reception turns it into a booking with the normal form, or declines it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->string('booking_slug', 60)->nullable()->unique()->after('inactive_since');
        });
        Schema::create('booking_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->nullable()->constrained()->nullOnDelete();
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedTinyInteger('adults')->default(1);
            $table->unsignedTinyInteger('children')->default(0);
            $table->string('name', 120);
            $table->string('phone', 30);
            $table->string('email', 120)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('status', 20)->default('new'); // new, accepted, declined
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index(['hotel_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_requests');
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropUnique(['booking_slug']);
            $table->dropColumn('booking_slug');
        });
    }
};
