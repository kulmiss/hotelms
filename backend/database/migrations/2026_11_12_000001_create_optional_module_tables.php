<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 5: cashier shift close, housekeeping, staff shifts and attendance (database-schema.md sections 8–10). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->string('status', 10)->default('open'); // open, closed, approved
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['hotel_id', 'user_id', 'status']);
        });

        Schema::create('cashier_session_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_session_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 3);
            $table->decimal('opening_amount', 14, 2)->default(0);
            $table->decimal('expected_amount', 14, 2)->nullable();
            $table->decimal('counted_amount', 14, 2)->nullable();
            $table->decimal('difference', 14, 2)->nullable(); // counted − expected (negative = short)
            $table->timestamps();
            $table->unique(['cashier_session_id', 'currency']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('cashier_session_id')->references('id')->on('cashier_sessions')->nullOnDelete();
        });

        Schema::create('housekeeping_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 15)->default('cleaning'); // cleaning, inspection, maintenance
            $table->string('priority', 10)->default('normal'); // low, normal, high
            $table->string('status', 15)->default('pending'); // pending, in_progress, done
            $table->dateTime('due_at')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['hotel_id', 'branch_id', 'status']);
            $table->index(['assigned_to', 'status']);
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->time('start_time');
            $table->time('end_time'); // may be earlier than start (night shift)
            $table->timestamps();
        });

        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('status', 10)->default('scheduled'); // scheduled, swapped, cancelled
            $table->timestamps();
            $table->unique(['user_id', 'shift_id', 'date']);
            $table->index(['hotel_id', 'branch_id', 'date']);
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->dateTime('clock_in_at')->nullable();
            $table->dateTime('clock_out_at')->nullable();
            $table->string('method', 10); // self, kiosk_pin, manual
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 10)->default('present'); // present, late, absent, leave
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['hotel_id', 'user_id', 'date']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Keyed hash (HMAC) of the kiosk PIN, so the kiosk can find the person from the PIN alone.
            $table->string('attendance_pin', 64)->nullable()->after('password');
            $table->index(['hotel_id', 'attendance_pin']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['hotel_id', 'attendance_pin']);
            $table->dropColumn('attendance_pin');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['cashier_session_id']);
        });
        foreach (['attendances', 'shift_assignments', 'shifts', 'housekeeping_tasks', 'cashier_session_balances', 'cashier_sessions'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
