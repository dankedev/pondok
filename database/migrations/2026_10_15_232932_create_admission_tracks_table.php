<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jalur pendaftaran. Tidak dihapus, cukup dinonaktifkan.
        Schema::create('admission_tracks', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            // Beasiswa yang diterapkan otomatis saat pendaftar jalur ini diterima (F011).
            $table->foreignId('scholarship_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('terms')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_tracks');
    }
};
