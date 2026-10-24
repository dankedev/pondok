<?php

namespace Database\Seeders\Demo;

use App\Actions\Kedisiplinan\CancelStudentViolation;
use App\Actions\Kedisiplinan\RecordStudentViolation;
use App\Models\Student;
use App\Models\StudentViolation;
use App\Models\ViolationType;

/**
 * Beberapa pelanggaran santri (F026) lewat `RecordStudentViolation`, satu di antaranya dibatalkan lewat
 * `CancelStudentViolation`, sehingga activity log `kedisiplinan` tercatat. Musyrif demo mencatat untuk
 * penghuni kamar binaannya; kesantrian demo untuk santri jenjangnya. Jenis dari katalog bawaan
 * (`DisciplineSeeder`). Hanya dibuat bila belum ada catatan sama sekali (idempotent).
 */
final class DemoDiscipline
{
    /**
     * Catatan: [peran pencatat, urutan santri yang terlihat peran itu, kunci jenis bawaan, hari lalu,
     * keterangan, alasan batal (null = tidak dibatalkan)].
     */
    private const VIOLATIONS = [
        ['musyrif', 0, 'late_prayer', 8, 'Masuk masjid setelah iqamah shalat Subuh.', null],
        ['musyrif', 0, 'late_prayer', 3, 'Tertidur setelah murajaah malam, tertinggal rakaat pertama shalat Subuh.', null],
        ['musyrif', 1, 'phone', 5, 'Telepon genggam disimpan di lemari, diserahkan ke bagian kesantrian.', null],
        ['kesantrian', 0, 'leave_without_permission', 6, 'Keluar ke warung depan pondok tanpa izin musyrif.', null],
        ['kesantrian', 1, 'improper_dress', 2, null, 'Salah santri; catatan dipindahkan ke santri yang benar.'],
    ];

    public function __construct(private readonly DemoContext $context) {}

    public function run(): void
    {
        if (StudentViolation::withoutGlobalScopes()->exists()) {
            return;
        }

        foreach (self::VIOLATIONS as [$role, $index, $typeKey, $daysAgo, $description, $cancelReason]) {
            $actor = $this->context->user($role);
            $student = Student::visibleStudentsFor($actor)->active()->orderBy('name')->skip($index)->first();
            $type = ViolationType::query()->where('name', __("pondok.kedisiplinan.defaults.types.{$typeKey}"))->first();

            if (! $student instanceof Student || ! $type instanceof ViolationType) {
                $this->context->skip('kedisiplinan', "santri atau jenis {$typeKey} untuk akun {$role} tidak ditemukan");

                continue;
            }

            $violation = app(RecordStudentViolation::class)->handle($actor, [
                'student_id' => $student->id,
                'violation_type_id' => $type->id,
                'occurred_on' => $this->context->calendar->daysAgo($daysAgo)->toDateString(),
                'description' => $description,
            ]);

            if ($cancelReason !== null) {
                app(CancelStudentViolation::class)->handle($actor, $violation, $cancelReason);
            }
        }
    }
}
