<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner (QA 8 Nov): every action that takes money or creates / changes data is saved once. The app sends one
 * Idempotency-Key per submission (the same key again for retries); the server keeps the key, who sent it, the
 * endpoint, a hash of the request and the answer for at least 24 hours (`App\Http\Middleware\Idempotent`).
 * The unique (user_id, key) index makes sure that of two identical requests arriving at the same moment only one runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('endpoint', 255);          // e.g. "POST api/v1/reservations/12/payments"
            $table->char('request_hash', 64);        // sha256 of the request body
            $table->string('status', 12)->default('processing'); // processing | done
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
