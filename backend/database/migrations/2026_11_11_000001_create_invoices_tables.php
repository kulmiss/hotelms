<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 4: invoices (guest bills), their lines and tax snapshots (database-schema.md section 7). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete(); // set when split per room
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->string('currency', 3);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0); // taxes added on top
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('paid_total', 14, 2)->default(0);
            $table->decimal('balance', 14, 2)->default(0);
            $table->string('status', 20)->default('open'); // open, partially_paid, paid, outstanding, void
            $table->dateTime('issued_at');
            $table->foreignId('outstanding_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('outstanding_approved_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->string('void_reason', 200)->nullable();
            $table->timestamps();
            $table->unique(['hotel_id', 'number']);
            $table->index(['hotel_id', 'status']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // room, extra, early_check_in, late_check_out, cancellation_fee, discount
            $table->string('description', 200);
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('amount', 14, 2); // quantity × unit price (negative for discounts)
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->boolean('is_waived')->default(false);
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reservation_room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('charge_type_id')->nullable()->constrained()->nullOnDelete();
            $table->date('service_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['invoice_id', 'type']);
        });

        Schema::create('invoice_item_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 60);
            $table->decimal('rate', 5, 2);
            $table->boolean('is_inclusive')->default(false);
            $table->decimal('amount', 14, 2); // tax part (inside the price when inclusive)
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
        });
        Schema::dropIfExists('invoice_item_taxes');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
