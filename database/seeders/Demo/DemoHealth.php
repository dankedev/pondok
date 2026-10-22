<?php

namespace Database\Seeders\Demo;

use App\Actions\Kesehatan\RecordHealthVisit;
use App\Models\HealthVisit;
use App\Models\Student;

/**
 * Beberapa kunjungan kesehatan santri (F031) lewat `RecordHealthVisit` sehingga activity log tercatat.
 * Musyrif demo mencatat untuk penghuni kamar binaannya; kesantrian demo untuk santri jenjangnya.
 * Hanya dibuat bila belum ada kunjungan sama sekali (idempotent).
 */
final class DemoHealth
{
    /**
     * Kunjungan: [peran pencatat, urutan santri yang terlihat peran itu, hari lalu, jam, keluhan,
     * tindakan, perlu rujukan, perlu izin pulang, catatan].
     */
    private const VISITS = [
        ['musyrif', 0, 6, '21:15', 'Demam dan menggigil sejak sore', 'Kompres hangat, parasetamol 500 mg', false, false, 'Dipantau sampai pagi, suhu turun.'],
        ['musyrif', 1, 2, '05:40', 'Batuk dan pilek', 'Minum air hangat, obat batuk sirup', false, false, null],
        ['kesantrian', 0, 9, '10:30', 'Nyeri perut kanan bawah', 'Istirahat di UKS, tidak membaik', true, true, 'Dirujuk ke puskesmas, dijemput wali.'],
        ['kesantrian', 1, 4, '13:00', 'Luka lecet di lutut setelah olahraga', 'Dibersihkan dan diplester', false, false, null],
        ['kesantrian', 2, 1, '08:20', 'Sakit gigi geraham', 'Obat pereda nyeri', true, false, 'Perlu diperiksa dokter gigi.'],
    ];

    public function __construct(private readonly DemoContext $context) {}

    public function run(): void
    {
        if (HealthVisit::withoutUnitScope()->exists()) {
            return;
        }

        foreach (self::VISITS as [$role, $index, $daysAgo, $time, $complaint, $treatment, $referral, $leave, $note]) {
            $actor = $this->context->user($role);
            $student = HealthVisit::visibleStudents($actor)->active()->orderBy('name')->skip($index)->first();

            if (! $student instanceof Student) {
                $this->context->skip('kesehatan', "santri untuk akun {$role} tidak ditemukan");

                continue;
            }

            [$hour, $minute] = array_map('intval', explode(':', $time));

            app(RecordHealthVisit::class)->handle($actor, [
                'student_id' => $student->id,
                'visited_at' => $this->context->calendar->daysAgo($daysAgo)->setTime($hour, $minute)->toDateTimeString(),
                'complaint' => $complaint,
                'treatment' => $treatment,
                'note' => $note,
                'needs_referral' => $referral,
                'needs_leave' => $leave,
            ]);
        }
    }
}
