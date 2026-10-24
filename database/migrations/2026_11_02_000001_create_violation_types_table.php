<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Katalog jenis pelanggaran berpoin (F026). Tidak dihapus, cukup dinonaktifkan.
        Schema::create('violation_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('category', 10);
            $table->unsignedSmallInteger('points');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('violation_types');
    }
};
