<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Free hotels nobody signed in to for 90 days are flagged for the Super Admin (owner, 14 Nov 2026). Nothing is deleted. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->timestamp('inactive_since')->nullable()->after('signup_reason')->index();
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropIndex(['inactive_since']);
            $table->dropColumn('inactive_since');
        });
    }
};
