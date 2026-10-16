<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Peserta prestasi: satu prestasi beregu dicatat sekali untuk banyak santri.
        // Santri hanya di-soft delete, sehingga force delete santri peserta tertahan.
        // `rank` kata terpesan di MySQL 8: akses hanya lewat builder/Eloquent (identifier dikutip).
        Schema::create('achievement_student', function (Blueprint $table) {
            $table->foreignId('achievement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('rank', 50);
            $table->string('role', 50)->nullable();

            $table->primary(['achievement_id', 'student_id']);
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievement_student');
    }
};
