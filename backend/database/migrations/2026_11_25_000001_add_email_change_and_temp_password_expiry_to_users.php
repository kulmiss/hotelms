<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SECURITY_REPORT M7, L6: a new sign-in email waits until the link sent to it is opened, and a
 * temporary password works for 7 days only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email', 190)->nullable()->after('email');
            $table->char('pending_email_token', 64)->nullable()->index()->after('pending_email');
            $table->timestamp('pending_email_expires_at')->nullable()->after('pending_email_token');
            $table->timestamp('temporary_password_expires_at')->nullable()->after('must_change_password');
        });
        // Temporary passwords given before this change get the full 7 days from now.
        DB::table('users')->where('must_change_password', true)->update(['temporary_password_expires_at' => now()->addDays(7)]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['pending_email_token']);
            $table->dropColumn(['pending_email', 'pending_email_token', 'pending_email_expires_at', 'temporary_password_expires_at']);
        });
    }
};
