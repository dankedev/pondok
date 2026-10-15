<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Angkatan: kelompok santri berdasarkan tahun masuk per jenjang.
        Schema::create('cohorts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('entry_year');
            $table->string('name');
            $table->timestamps();

            $table->unique(['unit_id', 'entry_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cohorts');
    }
};
