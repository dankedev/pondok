<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gelombang PSB per tahun ajaran tujuan. Tidak dihapus, cukup ditutup (`is_open`).
        Schema::create('admission_waves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->date('opens_on');
            $table->date('closes_on');
            // NULL = tanpa batas.
            $table->unsignedInteger('quota')->nullable();
            $table->boolean('is_open')->default(true);
            $table->timestamps();

            $table->unique(['academic_year_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_waves');
    }
};
