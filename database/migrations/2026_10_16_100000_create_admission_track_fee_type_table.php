<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jenis biaya yang mendapat beasiswa jalur (F011). Pemetaan kosong berarti
 * semua jenis biaya `saat_diterima` yang aktif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_track_fee_type', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_track_id')->constrained()->restrictOnDelete();
            $table->foreignId('fee_type_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['admission_track_id', 'fee_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_track_fee_type');
    }
};
