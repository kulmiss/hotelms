<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Watchdog (Super Admin): small events the system notices while it runs (refused requests, failed sign-ins,
 * server errors) and the findings the checks make from them and from the data (security, bugs, mixed data).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watch_events', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20); // denied, missing, error, login_failed
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('hotel_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('method', 8)->nullable();
            $table->string('path', 200)->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->string('message', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['kind', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['ip', 'created_at']);
        });

        Schema::create('watchdog_findings', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 120)->unique();
            $table->string('check', 40);
            $table->string('severity', 10); // critical, warning, info
            $table->foreignId('hotel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 250);
            $table->json('details')->nullable(); // [[label, value], …]
            $table->text('advice')->nullable();
            $table->string('link', 250)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('times')->default(1);
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('muted_until')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->index(['resolved_at', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchdog_findings');
        Schema::dropIfExists('watch_events');
    }
};
