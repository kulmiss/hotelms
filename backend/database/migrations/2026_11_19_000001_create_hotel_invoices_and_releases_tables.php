<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Super Admin: subscription invoices emailed to hotels, releases and "What's new" (super-admin.md 3 and 5). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30)->unique();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('billing_cycle', 10);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('rooms')->default(0);
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('ETB');
            $table->string('status', 10)->default('unpaid'); // unpaid, paid, void
            $table->date('due_date');
            $table->timestamp('emailed_at')->nullable();
            $table->foreignId('subscription_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['hotel_id', 'status']);
        });

        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->string('version', 20);
            $table->string('title', 150);
            $table->text('body');
            $table->string('status', 10)->default('draft'); // draft, published
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releases');
        Schema::dropIfExists('hotel_invoices');
    }
};
