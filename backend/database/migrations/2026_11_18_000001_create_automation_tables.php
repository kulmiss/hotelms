<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Automation (automation.md, W3): in-app notifications, ready-made automations, rule builder, approvals inbox. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->constrained()->cascadeOnDelete(); // null = HotelMS team
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 60)->nullable(); // automation key or rule:ID
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('link', 200)->nullable();
            $table->boolean('urgent')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['user_id', 'read_at', 'id']);
        });

        Schema::create('automation_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->boolean('enabled');
            $table->json('options')->nullable();
            $table->timestamps();
            $table->unique(['hotel_id', 'key']);
        });

        // Remembers what already fired, so nobody gets the same message twice.
        Schema::create('automation_firings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('subject', 120);
            $table->timestamp('fired_at');
            $table->unique(['hotel_id', 'key', 'subject']);
            $table->index('fired_at');
        });

        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('enabled')->default(true);
            $table->string('trigger', 40);
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->unsignedInteger('fired_count')->default(0);
            $table->timestamp('last_fired_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['hotel_id', 'trigger', 'enabled']);
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 30); // price_up, price_down, refund
            $table->string('title', 200);
            $table->json('payload');
            $table->string('fingerprint', 120)->nullable();
            $table->string('status', 10)->default('pending'); // pending, approved, rejected, expired
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('note', 200)->nullable();
            $table->timestamps();
            $table->index(['hotel_id', 'status']);
            $table->unique(['hotel_id', 'fingerprint']);
        });

        // New permission for branch managers in existing hotels (Hotel Admins have everything).
        DB::table('roles')->where('key', 'branch_manager')->pluck('id')->each(function ($roleId) {
            if (! DB::table('role_permissions')->where('role_id', $roleId)->where('permission', 'approvals.decide')->exists()) {
                DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission' => 'approvals.decide']);
            }
        });
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', 'approvals.decide')->delete();
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('automation_rules');
        Schema::dropIfExists('automation_firings');
        Schema::dropIfExists('automation_settings');
        Schema::dropIfExists('app_notifications');
    }
};
