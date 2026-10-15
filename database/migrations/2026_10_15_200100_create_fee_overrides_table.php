<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keringanan per santri (atau pendaftar) per jenis biaya dengan masa berlaku.
        // Data keuangan: semua FK restrict; keringanan diakhiri lewat `valid_until`, tidak dihapus.
        Schema::create('fee_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->nullable()->constrained()->restrictOnDelete();
            // Tanpa FK sampai F010 menambah tabel `applicants` beserta constraint-nya.
            $table->unsignedBigInteger('applicant_id')->nullable()->index();
            $table->foreignId('fee_type_id')->constrained()->restrictOnDelete();
            // NULL = keringanan khusus, bukan program beasiswa.
            $table->foreignId('scholarship_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('mode', ['nominal', 'potongan', 'persen']);
            // Rupiah (nominal 0 = gratis, potongan) atau persen 0-100.
            $table->unsignedBigInteger('value');
            $table->string('reason');
            $table->date('valid_from');
            // NULL = berlaku sampai lulus; inklusif.
            $table->date('valid_until')->nullable();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'fee_type_id', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_overrides');
    }
};
