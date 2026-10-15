<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_requirements', function (Blueprint $table) {
            $table->id();
            // NULL = berlaku untuk semua jenjang.
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->boolean('is_required')->default(true);
            $table->unsignedTinyInteger('sort_order')->default(0);
            // NULL tidak bentrok di unique, jadi `unit_id` dipetakan ke 0 lewat kolom generated.
            $table->unsignedBigInteger('unit_key')->storedAs('COALESCE(unit_id, 0)');
            $table->timestamps();

            $table->unique(['document_type_id', 'unit_key'], 'admission_requirements_type_unit_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_requirements');
    }
};
