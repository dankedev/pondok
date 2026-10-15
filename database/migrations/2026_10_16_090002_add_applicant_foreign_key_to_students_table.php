<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel anak `students` yang FK-nya `cascadeOnDelete`.
     */
    private const CASCADING_CHILDREN = ['enrollments', 'student_guardian', 'room_assignments'];

    /**
     * Menutup utang skema: `students.applicant_id` kini ber-FK ke `applicants`.
     *
     * Di SQLite Laravel membangun ulang tabel (`drop` lalu `rename`) di dalam
     * transaksi bawaan, tempat `PRAGMA foreign_keys = OFF` tidak berlaku. Karena
     * itu pemeriksaan FK ditunda sampai commit (`defer_foreign_keys`), dan baris
     * tabel anak ber-cascade disalin lalu dikembalikan agar tidak ikut terhapus
     * oleh `drop table students` (test AC-3/AC-4 F010).
     */
    public function up(): void
    {
        $this->rebuild(function (Blueprint $table): void {
            $table->foreign('applicant_id')->references('id')->on('applicants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $this->rebuild(function (Blueprint $table): void {
            $table->dropForeign(['applicant_id']);
        });
    }

    private function rebuild(Closure $callback): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('students', $callback);

            return;
        }

        DB::statement('PRAGMA defer_foreign_keys = ON');

        // Kolom generated (mis. `room_assignments.active_marker`) diisi ulang database.
        $children = collect(self::CASCADING_CHILDREN)->mapWithKeys(function (string $table): array {
            $columns = collect(Schema::getColumns($table))->whereNull('generation')->pluck('name')->all();

            return [$table => DB::table($table)->get($columns)->map(fn ($row): array => (array) $row)->all()];
        });

        Schema::table('students', $callback);

        foreach ($children as $table => $rows) {
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table($table)->insertOrIgnore($chunk);
            }
        }
    }
};
