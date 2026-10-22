<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Setoran hafalan sebagai rentang surah:ayat. Koreksi = hapus lalu catat ulang.
        Schema::create('memorization_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('halaqah_id')->constrained()->restrictOnDelete();
            $table->foreignId('listener_staff_id')->constrained('staff')->restrictOnDelete();
            $table->date('recorded_on');
            $table->enum('session', ['subuh', 'pagi', 'sore', 'maghrib', 'malam']);
            $table->enum('type', ['ziyadah', 'murajaah', 'tasmi']);
            $table->unsignedSmallInteger('from_surah_id');
            $table->foreign('from_surah_id')->references('id')->on('quran_surahs')->restrictOnDelete();
            $table->unsignedSmallInteger('from_ayah');
            $table->unsignedSmallInteger('to_surah_id');
            $table->foreign('to_surah_id')->references('id')->on('quran_surahs')->restrictOnDelete();
            $table->unsignedSmallInteger('to_ayah');
            $table->decimal('pages', 5, 1);
            $table->enum('grade', ['mumtaz', 'jayyid_jiddan', 'jayyid', 'maqbul', 'ulang']);
            $table->unsignedTinyInteger('mistakes')->default(0);
            $table->string('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'recorded_on']);
            $table->index(['halaqah_id', 'recorded_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memorization_records');
    }
};
