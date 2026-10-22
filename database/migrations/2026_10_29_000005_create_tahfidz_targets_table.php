<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Target hafalan per tahun ajaran, jenjang, dan tingkat (NULL = semua tingkat).
        Schema::create('tahfidz_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('grade')->nullable();
            $table->decimal('target_juz', 4, 1);
            // NULL tidak bentrok di unique, jadi dipetakan ke 0 (pola `fee_rates`).
            $table->unsignedTinyInteger('grade_key')->storedAs('COALESCE(grade, 0)');
            $table->timestamps();

            $table->unique(['academic_year_id', 'unit_id', 'grade_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tahfidz_targets');
    }
};
