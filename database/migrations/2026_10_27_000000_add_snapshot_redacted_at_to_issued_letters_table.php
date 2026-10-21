<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| F042: waktu data pribadi snapshot surat diredaksi setelah masa simpan
| (`RedactExpiredLetterSnapshots`). Null = snapshot masih utuh.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issued_letters', function (Blueprint $table) {
            $table->timestamp('snapshot_redacted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('issued_letters', function (Blueprint $table) {
            $table->dropColumn('snapshot_redacted_at');
        });
    }
};
