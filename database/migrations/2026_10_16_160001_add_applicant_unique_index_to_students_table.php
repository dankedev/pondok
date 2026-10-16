<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu santri per pendaftar (ERD `applicants |o--o| students`). NULL boleh
     * banyak di SQLite/MySQL/Postgres. `CREATE UNIQUE INDEX` tidak membangun
     * ulang tabel di SQLite, sehingga FK dan index lama `students` tetap utuh.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->unique('applicant_id', 'students_applicant_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->dropUnique('students_applicant_id_unique');
        });
    }
};
