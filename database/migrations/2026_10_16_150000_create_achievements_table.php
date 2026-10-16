<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Prestasi santri (F015). Tanpa soft delete; jenjang dibaca lewat santri peserta.
        // `academic_year_id` diturunkan dari `achieved_on` oleh Action. NULL `verified_by` = belum diverifikasi.
        Schema::create('achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('category', 20);
            $table->string('title');
            $table->string('event_name');
            $table->string('organizer')->nullable();
            $table->string('level', 20);
            $table->string('kind', 10);
            $table->date('achieved_on')->index();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->boolean('is_public')->default(false);
            $table->timestamps();

            $table->index(['academic_year_id', 'category', 'level'], 'achievements_year_category_level_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements');
    }
};
