<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hotels register themselves (owner, 14 Nov 2026). Super Admin → Settings → Sign-up: off / approval / open.
 * signup_status: null = made by the HotelMS team; unverified → (pending →) approved, or rejected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->string('signup_status', 20)->nullable()->after('is_demo')->index();
            $table->json('signup')->nullable()->after('signup_status');
            $table->timestamp('signup_decided_at')->nullable()->after('signup');
            $table->foreignId('signup_decided_by')->nullable()->after('signup_decided_at')->constrained('users')->nullOnDelete();
            $table->string('signup_reason', 500)->nullable()->after('signup_decided_by');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('email_verify_token', 64)->nullable()->after('email_verified_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['email_verify_token']);
            $table->dropColumn('email_verify_token');
        });
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signup_decided_by');
            $table->dropIndex(['signup_status']);
            $table->dropColumn(['signup_status', 'signup', 'signup_decided_at', 'signup_reason']);
        });
    }
};
