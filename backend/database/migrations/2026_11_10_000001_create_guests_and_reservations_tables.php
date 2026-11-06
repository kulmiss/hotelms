<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 3: guests, reservations, deposits (database-schema.md sections 5–7). */
return new class extends Migration
{
    public function up(): void
    {
        // Running numbers per hotel (booking codes, later invoice numbers).
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40); // e.g. reservation-2026
            $table->unsignedInteger('last')->default(0);
            $table->timestamps();
            $table->unique(['hotel_id', 'key']);
        });

        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('first_name', 60);
            $table->string('last_name', 60)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('nationality', 60)->nullable();
            $table->string('id_type', 20)->nullable(); // national_id, passport, kebele_id, driving_licence
            $table->string('id_number', 60)->nullable();
            $table->string('gender', 10)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_blacklisted')->default(false);
            $table->string('blacklist_reason', 200)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['hotel_id', 'phone']);
            $table->index(['hotel_id', 'id_number']);
            $table->index(['hotel_id', 'first_name', 'last_name']);
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->foreignId('guest_id')->constrained();
            $table->string('source', 20)->default('phone'); // walk_in, phone, email, agent, other
            $table->string('stay_type', 10)->default('nightly'); // nightly, day_use, hourly
            $table->dateTime('check_in_at');
            $table->dateTime('check_out_at');
            $table->unsignedTinyInteger('adults')->default(1);
            $table->unsignedTinyInteger('children')->default(0);
            $table->string('status', 15)->default('pending'); // pending, confirmed, checked_in, checked_out, cancelled, no_show
            $table->dateTime('expires_at')->nullable();
            $table->foreignId('cancellation_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->json('policy_snapshot')->nullable(); // the policy as it was at booking time
            $table->string('currency', 3);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0); // taxes added on top (not included in prices)
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('deposit_required', 14, 2)->default(0);
            $table->decimal('paid_total', 14, 2)->default(0); // deposits/payments minus refunds
            $table->decimal('cancellation_fee', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancel_reason', 200)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['hotel_id', 'code']);
            $table->index(['hotel_id', 'branch_id', 'status', 'check_in_at']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('reservation_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('check_in_at');
            $table->dateTime('check_out_at');
            // check_out_at plus the cleaning time for hourly / day-use stays (used for availability)
            $table->dateTime('blocked_until');
            $table->dateTime('actual_check_in_at')->nullable();
            $table->dateTime('actual_check_out_at')->nullable();
            $table->unsignedTinyInteger('adults')->default(1);
            $table->unsignedTinyInteger('children')->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('status', 15)->default('pending');
            $table->timestamps();
            $table->index(['room_type_id', 'status', 'check_in_at', 'blocked_until'], 'rr_type_availability');
            $table->index(['room_id', 'status', 'check_in_at', 'blocked_until'], 'rr_room_availability');
        });

        Schema::create('reservation_room_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_room_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('label', 60)->nullable(); // "Night", "Day use", "3 hours"
            $table->decimal('amount', 14, 2);
            $table->foreignId('rate_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rule_name', 60)->nullable();
            $table->timestamps();
        });

        Schema::create('reservation_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['reservation_room_id', 'guest_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('invoice_id')->nullable(); // invoices arrive in phase 4
            $table->foreignId('payment_method_id')->constrained();
            $table->unsignedBigInteger('cashier_session_id')->nullable(); // phase 5
            $table->string('type', 10); // deposit, payment, refund
            $table->decimal('amount', 14, 2); // in the currency paid
            $table->string('currency', 3);
            $table->decimal('exchange_rate', 14, 6)->default(1);
            $table->decimal('amount_in_base', 14, 2); // in the reservation / invoice currency
            $table->string('reference', 100)->nullable();
            $table->string('refund_reason', 200)->nullable();
            $table->dateTime('paid_at');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['hotel_id', 'paid_at']);
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        foreach (['payments', 'reservation_guests', 'reservation_room_rates', 'reservation_rooms', 'reservations', 'guests', 'number_sequences'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
