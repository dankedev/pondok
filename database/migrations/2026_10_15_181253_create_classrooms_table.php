<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rombel per tahun ajaran (1A, 1B, 1C), agar kelas tahun lalu tetap utuh untuk arsip.
        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('grade');
            $table->string('name');
            $table->enum('gender', ['L', 'P', 'campur'])->default('campur');
            $table->foreignId('homeroom_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->timestamps();

            $table->unique(['academic_year_id', 'unit_id', 'name']);
            $table->index(['academic_year_id', 'grade']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classrooms');
    }
};
