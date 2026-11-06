<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Super Admin areas (super-admin.md): support inbox, notes about hotels, data-access log. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // who asked (hotel staff)
            $table->string('channel', 10)->default('app'); // app, telegram, phone
            $table->string('subject', 150);
            $table->string('priority', 10)->default('normal'); // normal, urgent
            $table->string('status', 10)->default('open'); // open, waiting (for the hotel), solved
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'last_message_at']);
            $table->index(['hotel_id', 'status']);
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 10); // message (hotel), reply (HotelMS), note (HotelMS only), system
            $table->text('body');
            $table->timestamps();
            $table->index(['support_request_id', 'id']);
        });

        Schema::create('hotel_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 10)->nullable(); // phone, visit, telegram, email…
            $table->text('body');
            $table->timestamps();
            $table->index(['hotel_id', 'id']);
        });

        Schema::create('data_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('what', 30); // live_data, log_in_as
            $table->string('reason', 200);
            $table->timestamp('created_at')->nullable();
            $table->index(['hotel_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_access_logs');
        Schema::dropIfExists('hotel_notes');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_requests');
    }
};
