<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catatan wali kelas pada rapor diniyah, satu per santri per semester (F027).
        Schema::create('diniyah_report_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('semester');
            $table->text('note');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['student_id', 'academic_year_id', 'semester'], 'diniyah_report_notes_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diniyah_report_notes');
    }
};
