<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ambang poin kedisiplinan (F026): hanya label di rekap, tidak dirujuk tabel lain.
        Schema::create('discipline_levels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->unsignedSmallInteger('min_points')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discipline_levels');
    }
};
