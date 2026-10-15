<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jenis biaya (SPP, asrama, uang pangkal, ...). Tidak dihapus; dinonaktifkan lewat `is_active`.
        Schema::create('fee_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->enum('frequency', ['bulanan', 'tahunan', 'saat_daftar', 'saat_diterima', 'manual']);
            $table->enum('applies_to', ['semua', 'mukim', 'nonmukim'])->default('semua');
            // Jenis biaya dengan kelompok sama digabung dalam satu tagihan.
            $table->string('billing_group', 30);
            // Angka kecil dilunasi lebih dulu saat cicilan.
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['frequency', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_types');
    }
};
