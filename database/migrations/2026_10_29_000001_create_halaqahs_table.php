<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kelompok setoran hafalan per tahun ajaran. Tidak dihapus, cukup dinonaktifkan.
        Schema::create('halaqahs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            // NULL = lintas jenjang.
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('teacher_staff_id')->constrained('staff')->restrictOnDelete();
            $table->string('name');
            $table->enum('gender', ['L', 'P']);
            $table->string('schedule')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['academic_year_id', 'name']);
            $table->index('teacher_staff_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('halaqahs');
    }
};
