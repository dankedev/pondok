<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tagihan per santri (atau pendaftar) per periode per kelompok tagihan.
        // Data keuangan: semua FK restrict; tagihan dibatalkan lewat status `batal`, tidak dihapus.
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 20)->unique();
            // NULL hanya untuk tagihan pendaftar (F010).
            $table->foreignId('student_id')->nullable()->constrained()->restrictOnDelete();
            // Tanpa FK sampai F010 menambah tabel `applicants` beserta constraint-nya.
            $table->unsignedBigInteger('applicant_id')->nullable()->index();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            // Selalu tanggal 1 bulan tagihan, diisi lewat model (cast `date`).
            $table->date('period');
            // Salinan `fee_types.billing_group` saat tagihan dibuat.
            $table->string('billing_group');
            $table->unsignedBigInteger('total')->default(0);
            // Cache terbayar, diisi pembayaran (F007).
            $table->unsignedBigInteger('paid_total')->default(0);
            $table->enum('status', ['belum', 'sebagian', 'lunas', 'batal'])->default('belum');
            $table->date('due_on');
            // NULL bila dibuat scheduler atau command.
            $table->foreignId('generated_by')->nullable()->constrained('users')->restrictOnDelete();
            // 1 = tagihan aktif (belum/sebagian/lunas), NULL = batal. Diisi database.
            $table->unsignedTinyInteger('active_marker')->nullable()->storedAs("CASE WHEN status <> 'batal' THEN 1 END");
            $table->timestamps();

            // Penyimpangan sadar dari pola `COALESCE(col, 0)` di CLAUDE.md: kedua unique
            // berikut sengaja memakai semantik NULL-berbeda (SQLite, MySQL/MariaDB, Postgres).
            // - Tagihan `batal` (marker NULL) tidak saling bentrok dan tidak menghalangi
            //   satu tagihan aktif baru untuk kombinasi yang sama.
            // - Tagihan pendaftar (`student_id` NULL) tidak bentrok dengan tagihan santri,
            //   dan tagihan santri (`applicant_id` NULL) tidak bentrok dengan tagihan pendaftar.
            $table->unique(['student_id', 'period', 'billing_group', 'active_marker'], 'invoices_student_period_group_active_unique');
            $table->unique(['applicant_id', 'billing_group', 'active_marker'], 'invoices_applicant_group_active_unique');
            $table->index(['status', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
