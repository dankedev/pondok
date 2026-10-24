<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pelanggaran santri (F026). `points` snapshot poin jenis; tidak dihapus, koreksi lewat pembatalan.
        Schema::create('student_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('violation_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->date('occurred_on');
            $table->unsignedSmallInteger('points');
            $table->text('description')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'academic_year_id']);
            $table->index('occurred_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_violations');
    }
};
