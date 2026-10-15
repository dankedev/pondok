<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Program beasiswa: mengelompokkan keringanan per program dan donatur.
        // Tidak dihapus, cukup dinonaktifkan.
        Schema::create('scholarships', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            // Mode dan nilai bawaan untuk keringanan baru; keringanan menyimpan salinannya sendiri.
            $table->enum('mode', ['nominal', 'potongan', 'persen']);
            // Rupiah (nominal/potongan) atau persen 0-100.
            $table->unsignedBigInteger('default_value');
            $table->string('sponsor')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scholarships');
    }
};
