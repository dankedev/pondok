<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ujian tahfidz. `halaqah_id` = halaqah santri saat ujian, untuk visibilitas pengampu.
        Schema::create('tahfidz_exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('halaqah_id')->constrained()->restrictOnDelete();
            $table->enum('kind', ['kenaikan_juz', 'tasmi_5_juz', 'khataman']);
            $table->unsignedTinyInteger('juz_from');
            $table->unsignedTinyInteger('juz_to');
            $table->date('exam_on');
            $table->foreignId('examiner_staff_id')->constrained('staff')->restrictOnDelete();
            $table->unsignedTinyInteger('score');
            $table->enum('result', ['lulus', 'ulang']);
            // Nomor syahadah, manual dan opsional; hanya untuk hasil lulus.
            $table->string('certificate_no')->nullable()->unique();
            $table->timestamps();

            $table->index(['student_id', 'exam_on']);
            $table->index(['halaqah_id', 'exam_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tahfidz_exams');
    }
};
