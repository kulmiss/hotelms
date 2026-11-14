<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** No network addresses are kept for guests (owner, 14 Nov 2026). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_requests', function (Blueprint $table) {
            $table->dropColumn('ip');
        });
    }

    public function down(): void
    {
        Schema::table('booking_requests', function (Blueprint $table) {
            $table->string('ip', 45)->nullable()->after('handled_at');
        });
    }
};
