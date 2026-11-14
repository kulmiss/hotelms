<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Calendar sync with Airbnb, Booking.com and others by iCal links (owner, 14 Nov 2026):
 * - ical_feeds: links the hotel imports, for one room or a room type (several per room);
 * - ical_blocks: the busy dates read from them (they block the room; a clash with a booking is only a warning);
 * - rooms.ical_token: the secret export link of each room (can be reset).
 * Plans: none on Free, up to 10 rooms on Starter, all rooms on Pro and Business (switch limit.ical_rooms).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ical_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 60); // "Airbnb", "Booking.com"…
            $table->text('url'); // encrypted: the link is a secret
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_status', 10)->nullable(); // ok, error
            $table->string('last_error', 200)->nullable();
            $table->unsignedInteger('events_count')->default(0);
            $table->json('conflicts')->nullable(); // clashes with bookings (warning only)
            $table->timestamps();
            $table->index(['hotel_id', 'is_active']);
        });

        Schema::create('ical_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ical_feed_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('uid', 191);
            $table->date('starts_on');
            $table->date('ends_on'); // the day the guest leaves (not blocked as a night)
            $table->dateTime('starts_at'); // UTC: starts_on at the branch's check-in time
            $table->dateTime('ends_at'); // UTC: ends_on at the branch's check-out time
            $table->string('summary', 120)->nullable();
            $table->timestamps();
            $table->unique(['ical_feed_id', 'uid']);
            $table->index(['hotel_id', 'starts_at', 'ends_at']);
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->string('ical_token', 64)->nullable()->unique()->after('status'); // sha256 of the secret in the export link (to find the room)
            $table->text('ical_secret')->nullable()->after('ical_token'); // the secret itself, encrypted (to show the link again)
        });

        foreach (['free' => 0, 'starter' => 10] as $code => $limit) {
            $plan = DB::table('plans')->where('code', $code)->value('id');
            if ($plan && ! DB::table('feature_switches')->where(['key' => 'limit.ical_rooms', 'level' => 'plan', 'target_id' => $plan])->exists()) {
                DB::table('feature_switches')->insert(['key' => 'limit.ical_rooms', 'level' => 'plan', 'target_id' => $plan, 'value' => json_encode($limit), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        DB::table('feature_switches')->where('key', 'limit.ical_rooms')->delete();
        Schema::table('rooms', fn (Blueprint $table) => $table->dropColumn(['ical_token', 'ical_secret']));
        Schema::dropIfExists('ical_blocks');
        Schema::dropIfExists('ical_feeds');
    }
};
