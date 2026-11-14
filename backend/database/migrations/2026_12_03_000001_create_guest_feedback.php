<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guest feedback (owner, 14 Nov 2026): one per stay, from a secure link (no account, no cookies).
 * Stars for overall, cleanliness, staff, comfort and value, and a comment. Never changed after it is sent
 * (the hotel may only add a reply note); shown publicly only when the guest allowed it.
 * The dashboard (trends, per room and per staff) is on Starter and up (switch feature.feedback_dashboard).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->unique()->constrained()->cascadeOnDelete(); // one per stay
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('checked_out_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token', 64)->unique(); // sha256 of the secret in the link
            $table->text('secret'); // the secret itself, encrypted (to put the link in a message again)
            $table->unsignedTinyInteger('overall')->nullable();
            $table->unsignedTinyInteger('cleanliness')->nullable();
            $table->unsignedTinyInteger('staff')->nullable();
            $table->unsignedTinyInteger('comfort')->nullable();
            $table->unsignedTinyInteger('value')->nullable();
            $table->text('comment')->nullable();
            $table->boolean('public_ok')->default(false); // the guest allows showing it on the hotel's page
            $table->string('language', 5)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->text('reply')->nullable(); // the hotel's note (never changes the guest's words)
            $table->foreignId('replied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['hotel_id', 'submitted_at']);
        });

        foreach (DB::table('roles')->whereIn('key', ['branch_manager'])->where('is_default', true)->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission' => 'feedback.view']);
        }
        $free = DB::table('plans')->where('code', 'free')->value('id');
        if ($free && ! DB::table('feature_switches')->where(['key' => 'feature.feedback_dashboard', 'level' => 'plan', 'target_id' => $free])->exists()) {
            DB::table('feature_switches')->insert(['key' => 'feature.feedback_dashboard', 'level' => 'plan', 'target_id' => $free, 'value' => json_encode(false), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('feature_switches')->where('key', 'feature.feedback_dashboard')->delete();
        DB::table('role_permissions')->where('permission', 'feedback.view')->delete();
        Schema::dropIfExists('guest_feedback');
    }
};
