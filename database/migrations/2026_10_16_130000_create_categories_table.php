<?php

use App\Models\Category;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kategori konten publik: `post` (berita/kegiatan) atau `album` (galeri). Nama dan slug unik per type.
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);
            $table->string('name', 100);
            $table->string('slug', Category::SLUG_MAX_LENGTH);
            $table->timestamps();

            $table->unique(['type', 'name']);
            $table->unique(['type', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
