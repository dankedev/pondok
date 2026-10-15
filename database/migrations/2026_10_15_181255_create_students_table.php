<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            // Diduplikasi dari cohorts.unit_id agar scope per jenjang cepat; kecocokannya divalidasi di Action.
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('cohort_id')->constrained()->restrictOnDelete();
            // FK ke applicants ditambahkan oleh migrasi modul PSB.
            $table->unsignedBigInteger('applicant_id')->nullable()->index();
            $table->string('nis')->unique();
            $table->string('nisn')->nullable()->unique();
            $table->string('name');
            $table->enum('gender', ['L', 'P']);
            $table->string('birth_place');
            $table->date('birth_date');
            $table->text('address')->nullable();
            $table->enum('status', ['aktif', 'lulus', 'keluar', 'pindah'])->default('aktif');
            $table->date('entered_on');
            $table->string('photo_path')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['unit_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
