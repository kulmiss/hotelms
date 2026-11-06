<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Busy hotels (a year of stays): reports and dashboards read nights by date and bookings by day made. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_rooms', fn (Blueprint $t) => $t->index(['hotel_id', 'status', 'check_in_at'], 'rr_hotel_status_in'));
    }

    public function down(): void
    {
        Schema::table('reservation_rooms', fn (Blueprint $t) => $t->dropIndex('rr_hotel_status_in'));
    }
};
