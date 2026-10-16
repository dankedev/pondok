<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Utang skema F013: `documents.source_applicant_document_id` kini ber-FK ke
     * `applicant_documents` (`restrict`), diisi saat daftar ulang PSB (F012).
     *
     * Di SQLite Laravel membangun ulang tabel `documents` (self-FK
     * `replaced_by_id`) di dalam transaksi bawaan; pemeriksaan FK ditunda sampai
     * commit (`defer_foreign_keys`). Index lama dibuat ulang oleh Laravel.
     */
    public function up(): void
    {
        $this->alter(function (Blueprint $table): void {
            $table->foreign('source_applicant_document_id')->references('id')->on('applicant_documents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $this->alter(function (Blueprint $table): void {
            $table->dropForeign(['source_applicant_document_id']);
        });
    }

    private function alter(Closure $callback): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }

        Schema::table('documents', $callback);
    }
};
