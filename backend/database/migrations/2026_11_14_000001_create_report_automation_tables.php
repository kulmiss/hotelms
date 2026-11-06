<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 6c: saved views, custom reports, schedules, smart alerts, Telegram links (reports.md). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('report_key', 40);
            $table->string('name', 80);
            $table->json('filters');
            $table->boolean('is_pinned')->default(false);
            $table->json('shared_with_roles')->nullable(); // role ids
            $table->timestamps();
        });

        Schema::create('custom_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 80);
            $table->json('measures');
            $table->string('group_by', 20);
            $table->json('filters')->nullable();
            $table->string('chart_type', 10)->default('table'); // table, bar
            $table->json('shared_with_roles')->nullable();
            $table->timestamps();
        });

        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 80);
            $table->string('report_key', 40)->nullable(); // daily, performance, payments… (or a custom report)
            $table->foreignId('custom_report_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('frequency', 10); // daily, weekly, monthly
            $table->unsignedTinyInteger('day')->nullable(); // weekday 1–7 or day of month 1–28
            $table->string('time', 5)->default('07:00'); // hotel time
            $table->json('channels'); // ["email", "telegram"]
            $table->json('recipients'); // user ids
            $table->json('extra_emails')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->timestamps();
            $table->index(['is_active', 'next_run_at']);
        });

        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // cash_short, unusual_discounts, slow_booking, pending_expiring, target_risk, rate_stale
            $table->json('threshold')->nullable();
            $table->json('channels'); // ["app", "email", "telegram"]
            $table->json('recipients')->nullable(); // user ids; empty = Hotel Admins
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->unique(['hotel_id', 'type']);
        });

        Schema::create('alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 120); // same problem is reported once
            $table->string('title', 200);
            $table->json('payload')->nullable();
            $table->string('link', 200)->nullable();
            $table->timestamp('fired_at');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->unique(['alert_rule_id', 'fingerprint']);
            $table->index(['hotel_id', 'acknowledged_at']);
        });

        Schema::create('telegram_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16)->nullable()->unique(); // one-time link code
            $table->timestamp('code_expires_at')->nullable();
            $table->string('chat_id', 40)->nullable();
            $table->string('chat_name', 120)->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        foreach (['telegram_links', 'alert_events', 'alert_rules', 'report_schedules', 'custom_reports', 'saved_views'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
