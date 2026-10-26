<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mapel diniyah per jenjang (F027). Tidak dihapus, cukup dinonaktifkan.
        Schema::create('diniyah_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->unsignedTinyInteger('sort_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['unit_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diniyah_subjects');
    }
};
