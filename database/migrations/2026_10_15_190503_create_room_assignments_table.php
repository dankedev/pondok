<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Riwayat kamar santri. Pindah kamar menutup baris lama (`end_on`) dan menambah baris baru.
        Schema::create('room_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->date('start_on');
            $table->date('end_on')->nullable();
            $table->string('note')->nullable();
            // 1 = penempatan aktif, NULL = riwayat. NULL tidak bentrok di unique
            // (SQLite, MySQL/MariaDB, Postgres), sehingga satu santri hanya
            // bisa punya satu penempatan aktif.
            $table->unsignedTinyInteger('active_marker')->nullable()->storedAs('CASE WHEN end_on IS NULL THEN 1 END');
            $table->timestamps();

            $table->index(['student_id', 'end_on']);
            $table->index(['room_id', 'end_on']);
            $table->unique(['student_id', 'active_marker']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_assignments');
    }
};
