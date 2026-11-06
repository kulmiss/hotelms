<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Super Admin switches and banners (decisions.md W4; super-admin.md). Platform tables: no hotel scope. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_switches', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60);
            $table->string('level', 10); // everyone, plan, hotel
            $table->unsignedBigInteger('target_id')->nullable(); // plan or hotel id
            $table->json('value'); // true / false / a number
            $table->timestamp('starts_at')->nullable(); // scheduled switch
            $table->timestamp('ends_at')->nullable(); // gifts with an end date
            $table->string('reason', 200)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['key', 'level', 'target_id']);
        });

        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('message', 300);
            $table->string('tone', 10)->default('info'); // info, warn, danger
            $table->json('hotel_ids')->nullable(); // null = all hotels
            $table->json('plan_ids')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
        Schema::dropIfExists('feature_switches');
    }
};
