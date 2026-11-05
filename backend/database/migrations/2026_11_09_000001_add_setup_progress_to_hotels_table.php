<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Setup walkthrough progress (screen-list.md section 12). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->json('setup_steps')->nullable()->after('is_demo'); // {"profile": "done", "taxes": "skipped", …}
            $table->timestamp('setup_completed_at')->nullable()->after('setup_steps');
        });
    }

    public function down(): void
    {
        Schema::table('hotels', function (Blueprint $table) {
            $table->dropColumn(['setup_steps', 'setup_completed_at']);
        });
    }
};
