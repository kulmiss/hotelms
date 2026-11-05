<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Phase 7a: two-step sign-in, devices, and encrypted guest ID numbers and notes (decisions.md → Security and backups). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('two_factor_method', 10)->nullable()->after('password'); // app, email
            $table->text('two_factor_secret')->nullable()->after('two_factor_method'); // encrypted
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret'); // encrypted list of hashes
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });

        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hotel_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64); // sha256 of the device cookie
            $table->string('name', 120);
            $table->string('ip', 45)->nullable();
            $table->string('status', 12)->default('active'); // active, pending, signed_out, blocked
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'token_hash']);
            $table->index(['hotel_id', 'status']);
        });

        // Guest ID numbers and notes are encrypted; a keyed hash keeps exact ID search and duplicate hints working.
        Schema::table('guests', function (Blueprint $table) {
            $table->dropIndex(['hotel_id', 'id_number']);
        });
        Schema::table('guests', function (Blueprint $table) {
            $table->text('id_number')->nullable()->change();
            $table->string('id_number_hash', 64)->nullable()->after('id_number');
            $table->index(['hotel_id', 'id_number_hash']);
        });
        DB::table('guests')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $g) {
                DB::table('guests')->where('id', $g->id)->update([
                    'id_number' => $g->id_number === null ? null : Crypt::encryptString($g->id_number),
                    'id_number_hash' => $g->id_number === null ? null : hash_hmac('sha256', strtoupper(preg_replace('/\s+/', '', $g->id_number)), (string) config('app.key')),
                    'notes' => $g->notes === null ? null : Crypt::encryptString($g->notes),
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::table('guests')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $g) {
                DB::table('guests')->where('id', $g->id)->update([
                    'id_number' => $g->id_number === null ? null : mb_substr(Crypt::decryptString($g->id_number), 0, 60),
                    'notes' => $g->notes === null ? null : Crypt::decryptString($g->notes),
                ]);
            }
        });
        Schema::table('guests', function (Blueprint $table) {
            $table->dropIndex(['hotel_id', 'id_number_hash']);
            $table->dropColumn('id_number_hash');
        });
        Schema::table('guests', function (Blueprint $table) {
            $table->string('id_number', 60)->nullable()->change();
            $table->index(['hotel_id', 'id_number']);
        });
        Schema::dropIfExists('user_devices');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_method', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
