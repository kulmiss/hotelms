<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Open stay (workflows.md Q16): a walk-in who does not know how long they stay. The stay always runs to the
 * next check-out time; while the guest is still in, one more night is added at each check-out time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->boolean('open_stay')->default(false)->after('stay_type');
            $table->index(['status', 'open_stay', 'check_out_at']);
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex(['status', 'open_stay', 'check_out_at']);
            $table->dropColumn('open_stay');
        });
    }
};
