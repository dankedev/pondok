<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F048: template halaman (`default` | `contact`). Halaman ber-slug `kontak` yang sudah ada
 * menjadi `contact` agar kartu kontaknya tetap tampil; slug tidak disentuh. Nilai valid dijaga
 * di Action (tanpa enum DB). Template `contact` tidak bisa dipulihkan setelah `down()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('pages', 'template')) {
            return;
        }

        Schema::table('pages', function (Blueprint $table): void {
            $table->string('template', 20)->default('default')->after('status');
        });

        DB::table('pages')->where('slug', 'kontak')->update(['template' => 'contact']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('pages', 'template')) {
            Schema::table('pages', function (Blueprint $table): void {
                $table->dropColumn('template');
            });
        }
    }
};
