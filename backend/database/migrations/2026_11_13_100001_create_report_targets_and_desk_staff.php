<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Monthly targets per branch (reports.md feature 7) and who checked guests in / out (for "My shift summary"). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7); // 2026-11
            $table->decimal('revenue_target', 14, 2)->nullable();
            $table->decimal('occupancy_target', 5, 2)->nullable(); // percent
            $table->timestamps();
            $table->unique(['branch_id', 'month']);
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignId('checked_in_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->foreignId('checked_out_by')->nullable()->after('checked_in_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checked_out_by');
            $table->dropConstrainedForeignId('checked_in_by');
        });
        Schema::dropIfExists('report_targets');
    }
};
