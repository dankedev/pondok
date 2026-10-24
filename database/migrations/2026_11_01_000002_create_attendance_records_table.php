<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kehadiran santri per sesi absensi (F025). Status: hadir | sakit | izin | alpa.
        // `student_id` cascade hanya terjadi saat santri dihapus permanen (force delete).
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10);
            $table->timestamps();

            $table->unique(['attendance_session_id', 'student_id']);
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
