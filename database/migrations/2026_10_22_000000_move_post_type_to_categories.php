<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F049: jenis berita (`posts.type`) dihapus; pengelompokan hanya lewat kategori. Post `kegiatan`
 * dan `prestasi` yang belum berkategori dimasukkan ke kategori `post` "Kegiatan"/"Prestasi"
 * (dipakai ulang bila nama, tanpa membedakan huruf besar-kecil, atau slug-nya sudah ada; dibuat
 * hanya bila ada post yang membutuhkan). Post `berita` dan post yang sudah berkategori tidak
 * diubah. `down()` hanya menambah kolom `type` kembali (default `berita`); jenis asli tidak
 * dipulihkan dan kategori yang dibuat tetap ada.
 */
return new class extends Migration
{
    private const TYPES = ['kegiatan' => 'Kegiatan', 'prestasi' => 'Prestasi'];

    public function up(): void
    {
        foreach (self::TYPES as $slug => $name) {
            $posts = DB::table('posts')->where('type', $slug)->whereNull('category_id');

            if (! $posts->exists()) {
                continue;
            }

            $categoryId = DB::table('categories')
                ->where('type', 'post')
                ->where(fn ($query) => $query->where('slug', $slug)->orWhereRaw('lower(name) = ?', [strtolower($name)]))
                ->orderBy('id')
                ->value('id')
                ?? DB::table('categories')->insertGetId([
                    'type' => 'post', 'name' => $name, 'slug' => $slug, 'created_at' => now(), 'updated_at' => now(),
                ]);

            $posts->update(['category_id' => $categoryId]);
        }

        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['type']);
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('type', 10)->default('berita');
        });
    }
};
