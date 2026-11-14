<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Security event log (SECURITY_REPORT M9): sign-ins, lockouts, password / email / two-step changes, refusals. Kept 1 year. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('hotel_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('email', 190)->nullable(); // for tries with an unknown or wrong account
            $table->string('ip', 45)->nullable();
            $table->string('device', 80)->nullable();
            $table->string('detail', 300)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['hotel_id', 'created_at']);
            $table->index(['kind', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
