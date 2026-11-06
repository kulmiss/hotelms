<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Speed for big hotels (100+ rooms, years of history): the columns reports and the room board filter on. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_room_rates', fn (Blueprint $t) => $t->index(['date', 'label'], 'rrr_date_label'));
        Schema::table('invoice_items', fn (Blueprint $t) => $t->index(['hotel_id', 'service_date', 'type'], 'ii_hotel_date_type'));
        Schema::table('invoices', fn (Blueprint $t) => $t->index(['hotel_id', 'branch_id', 'issued_at'], 'inv_hotel_branch_issued'));
        Schema::table('payments', fn (Blueprint $t) => $t->index(['hotel_id', 'branch_id', 'paid_at'], 'pay_hotel_branch_paid'));
        Schema::table('reservations', fn (Blueprint $t) => $t->index(['hotel_id', 'created_at'], 'res_hotel_created'));
    }

    public function down(): void
    {
        Schema::table('reservation_room_rates', fn (Blueprint $t) => $t->dropIndex('rrr_date_label'));
        Schema::table('invoice_items', fn (Blueprint $t) => $t->dropIndex('ii_hotel_date_type'));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropIndex('inv_hotel_branch_issued'));
        Schema::table('payments', fn (Blueprint $t) => $t->dropIndex('pay_hotel_branch_paid'));
        Schema::table('reservations', fn (Blueprint $t) => $t->dropIndex('res_hotel_created'));
    }
};
