<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Riwayat keanggotaan halaqah, interval [joined_on, left_on). Pindah halaqah menutup baris lama.
        Schema::create('halaqah_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('halaqah_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->date('joined_on');
            $table->date('left_on')->nullable();
            // 1 = keanggotaan aktif, NULL = riwayat; satu santri hanya punya satu keanggotaan aktif
            // (pola `room_assignments`).
            $table->unsignedTinyInteger('active_marker')->nullable()->storedAs('CASE WHEN left_on IS NULL THEN 1 END');
            $table->timestamps();

            $table->index(['student_id', 'left_on']);
            $table->index(['halaqah_id', 'left_on']);
            $table->unique(['student_id', 'active_marker']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('halaqah_members');
    }
};
