<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company that pays the bill (owner, 2026-11-07): its name and TIN print on the invoice
 * (VAT invoices for business buyers show the buyer's TIN). Optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('buyer_company', 150)->nullable()->after('guest_id');
            $table->string('buyer_tin', 20)->nullable()->after('buyer_company');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['buyer_company', 'buyer_tin']);
        });
    }
};
