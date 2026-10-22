<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Capaian per juz, hanya ditulis `RefreshJuzProgress`. Juz yang belum disentuh tidak punya baris.
        Schema::create('student_juz_progress', function (Blueprint $table) {
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('juz');
            $table->enum('status', ['proses', 'hafal', 'lulus_ujian']);
            $table->date('completed_on')->nullable();
            $table->timestamps();

            $table->primary(['student_id', 'juz']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_juz_progress');
    }
};
