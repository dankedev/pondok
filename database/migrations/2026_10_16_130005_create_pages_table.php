<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Halaman statis tetap (profil, visi-misi, kontak), model `StaticPage`. Hanya bisa diubah.
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('title');
            $table->longText('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
