<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menutup utang skema: `invoices.applicant_id` kini ber-FK ke `applicants`.
     * Di SQLite Laravel membangun ulang tabel di dalam transaksi bawaan; index,
     * unique, kolom generated, dan FK tabel anak tetap utuh (test AC-3/AC-4 F010).
     * `PRAGMA foreign_keys = OFF` dari Laravel tidak berlaku di dalam transaksi,
     * jadi pemeriksaan FK SQLite ditunda sampai commit (`defer_foreign_keys`).
     */
    public function up(): void
    {
        $this->deferSqliteForeignKeys();

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('applicant_id')->references('id')->on('applicants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $this->deferSqliteForeignKeys();

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['applicant_id']);
        });
    }

    private function deferSqliteForeignKeys(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }
    }
};
