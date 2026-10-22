<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| F055: verifikasi email mulai diwajibkan di panel. Akun yang sudah ada ditandai
| terverifikasi agar tidak terkunci; tanggal verifikasi yang sudah ada tidak diubah.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    /**
     * Tidak bisa dibalik: baris yang ditandai migrasi ini tidak bisa dibedakan
     * dari yang memang sudah terverifikasi.
     */
    public function down(): void
    {
        //
    }
};
