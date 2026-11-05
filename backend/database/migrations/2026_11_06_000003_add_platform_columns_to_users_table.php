<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('hotel_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('phone')->nullable()->after('email');
            $table->boolean('is_super_admin')->default(false)->after('password');
            $table->string('status')->default('active')->after('is_super_admin');
            $table->boolean('must_change_password')->default(false)->after('status');
            $table->timestamp('last_login_at')->nullable()->after('must_change_password');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hotel_id');
            $table->dropColumn(['phone', 'is_super_admin', 'status', 'must_change_password', 'last_login_at', 'deleted_at']);
        });
    }
};
