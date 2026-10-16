<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Surat keluar (F014). Tidak pernah dihapus: surat salah dibatalkan (status `batal`) dan
        // nomornya tidak dipakai ulang. Isi surat dibekukan di `snapshot` saat terbit.
        Schema::create('issued_letters', function (Blueprint $table) {
            $table->id();
            $table->string('letter_no', 50)->unique();
            $table->enum('type', ['keterangan_aktif', 'pindah', 'rekomendasi', 'syahadah', 'kartu_santri']);
            // Penghitung nomor urut per (jenis, tahun kalender `issued_on`).
            $table->unsignedSmallInteger('sequence_year');
            $table->unsignedInteger('sequence');
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('signed_by_staff_id')->constrained('staff')->restrictOnDelete();
            $table->date('issued_on');
            $table->string('purpose')->nullable();
            $table->string('verification_code', 12)->unique();
            $table->json('snapshot');
            // Dicadangkan untuk arsip PDF/pindaian; tidak diisi F014.
            $table->string('file_path')->nullable();
            $table->enum('status', ['terbit', 'batal'])->default('terbit');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['type', 'sequence_year', 'sequence']);
            $table->index(['student_id', 'type']);
            $table->index(['status', 'issued_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_letters');
    }
};
