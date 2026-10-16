<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * F015: `posts.achievement_id` menjadi unique (satu berita per prestasi, termasuk baris
     * terhapus) dan ber-FK ke `achievements`. F016 mengizinkan angka sembarang, jadi id yatim
     * dibersihkan dulu. Tiap langkah blok `Schema::table` sendiri: di SQLite penambahan FK
     * membangun ulang tabel dan harus membaca index yang sudah final.
     */
    public function up(): void
    {
        DB::table('posts')
            ->whereNotNull('achievement_id')
            ->whereNotIn('achievement_id', DB::table('achievements')->select('id'))
            ->update(['achievement_id' => null]);

        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['achievement_id']);
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->unique('achievement_id');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->foreign('achievement_id')->references('id')->on('achievements')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropForeign(['achievement_id']);
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropUnique(['achievement_id']);
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->index('achievement_id');
        });
    }
};
