<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A photo of the guest's ID or passport (optional per hotel). The file is stored encrypted, like the ID number. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_id_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('mime', 60);
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('guest_id'); // one photo per guest; a new one replaces it
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_id_photos');
    }
};
