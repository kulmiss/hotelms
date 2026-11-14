<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guest messages (owner, 14 Nov 2026): templates per hotel (English and Amharic), a log per booking, consent and opt-out.
 * - Confirmation, day-before and welcome messages go unless the guest said no (guests.messages_opt_out).
 * - The thank-you message (with the feedback link) only when the guest ticked yes (reservations.feedback_consent).
 * - Automatic SMS only on paid plans (switch feature.auto_sms); WhatsApp / SMS by tap for everyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('key', 30); // confirmation, pre_arrival, welcome, thank_you, request_received, request_declined
            $table->string('language', 5); // en, am
            $table->string('subject', 150);
            $table->text('body');
            $table->timestamps();
            $table->unique(['hotel_id', 'key', 'language']);
        });

        Schema::create('guest_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('booking_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key', 30);
            $table->string('channel', 12); // email, sms, whatsapp (tap to send), sms_app (tap to send)
            $table->string('language', 5);
            $table->string('to', 150);
            $table->string('status', 10); // sent, failed, opened (tap to send: opened on the staff's phone), skipped
            $table->string('error', 200)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete(); // null = automatic
            $table->timestamps();
            $table->index(['hotel_id', 'reservation_id']);
        });

        Schema::table('guests', function (Blueprint $table) {
            $table->boolean('messages_opt_out')->default(false)->after('blacklist_reason'); // "no messages, please"
            $table->string('language', 5)->nullable()->after('messages_opt_out'); // for messages; null = the hotel's language
        });
        Schema::table('reservations', function (Blueprint $table) {
            $table->boolean('feedback_consent')->default(false)->after('notes'); // may we send the thank-you with the feedback link?
        });
        Schema::table('booking_requests', function (Blueprint $table) {
            $table->boolean('feedback_consent')->default(false)->after('note');
            $table->string('language', 5)->nullable()->after('feedback_consent'); // the language of the page the guest used
        });

        $free = DB::table('plans')->where('code', 'free')->value('id');
        if ($free && ! DB::table('feature_switches')->where(['key' => 'feature.auto_sms', 'level' => 'plan', 'target_id' => $free])->exists()) {
            DB::table('feature_switches')->insert(['key' => 'feature.auto_sms', 'level' => 'plan', 'target_id' => $free, 'value' => json_encode(false), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('feature_switches')->where('key', 'feature.auto_sms')->delete();
        Schema::table('booking_requests', fn (Blueprint $table) => $table->dropColumn(['feedback_consent', 'language']));
        Schema::table('reservations', fn (Blueprint $table) => $table->dropColumn('feedback_consent'));
        Schema::table('guests', fn (Blueprint $table) => $table->dropColumn(['messages_opt_out', 'language']));
        Schema::dropIfExists('guest_messages');
        Schema::dropIfExists('message_templates');
    }
};
