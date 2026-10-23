<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Periode pulang massal (F024), mis. libur semester.
        Schema::create('homecoming_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->date('depart_on');
            $table->date('return_on');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homecoming_periods');
    }
};
