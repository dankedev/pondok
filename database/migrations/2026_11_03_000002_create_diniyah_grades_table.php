<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nilai mapel diniyah per santri, tahun ajaran, dan semester (F027). Nilai kosong = tanpa baris.
        Schema::create('diniyah_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('semester');
            $table->foreignId('diniyah_subject_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('score');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['student_id', 'academic_year_id', 'semester', 'diniyah_subject_id'], 'diniyah_grades_unique');
            $table->index('diniyah_subject_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diniyah_grades');
    }
};
