<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tarif per jenis biaya, berlaku mulai tahun ajaran tertentu. Data keuangan:
        // semua FK restrict, tarif lama tetap tersimpan sebagai riwayat.
        Schema::create('fee_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('effective_from_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            // NULL = default jenjang.
            $table->foreignId('cohort_id')->nullable()->constrained()->restrictOnDelete();
            // NULL = semua asrama / nonmukim.
            $table->foreignId('dormitory_id')->nullable()->constrained()->restrictOnDelete();
            // Tanpa FK sampai F009 menambah tabel `admission_waves` beserta constraint-nya.
            $table->unsignedBigInteger('admission_wave_id')->nullable()->index();
            $table->unsignedBigInteger('amount');
            // NULL tidak bentrok di unique, jadi kolom nullable dipetakan ke 0 lewat
            // kolom generated agar kombinasi default jenjang tetap unik di semua driver.
            $table->unsignedBigInteger('cohort_key')->storedAs('COALESCE(cohort_id, 0)');
            $table->unsignedBigInteger('dormitory_key')->storedAs('COALESCE(dormitory_id, 0)');
            $table->unsignedBigInteger('wave_key')->storedAs('COALESCE(admission_wave_id, 0)');
            $table->timestamps();

            // Nama eksplisit: nama bawaan melebihi batas 64 karakter MySQL.
            $table->unique(
                ['fee_type_id', 'effective_from_year_id', 'unit_id', 'cohort_key', 'dormitory_key', 'wave_key'],
                'fee_rates_combination_unique',
            );
            $table->index(['fee_type_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_rates');
    }
};
