<?php

namespace Database\Seeders\Demo;

use App\Actions\Tahfidz\AddHalaqahMember;
use App\Actions\Tahfidz\RecordMemorization;
use App\Actions\Tahfidz\RecordTahfidzExam;
use App\Models\Halaqah;
use App\Models\MemorizationRecord;
use App\Models\TahfidzExam;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Halaqah tahfidz, anggota, setoran beberapa hari, dan satu ujian kenaikan juz bersyahadah (F023).
 * Semua lewat Action sehingga capaian juz dihitung `RefreshJuzProgress`. Setoran dan ujian hanya
 * dibuat bila halaqahnya belum punya setoran (idempotent).
 */
final class DemoTahfidz
{
    /**
     * Halaqah: [nama, jenjang|null, NIP pengampu, gender, jadwal, aktif, anggota].
     * Halaqah putri hanya contoh nonaktif: pondok demo khusus putra.
     */
    private const HALAQAHS = [
        ['Halaqah Abu Hurairah', 'SLTP', 'MAK-2018-006', 'L', "Ba'da Subuh dan Ba'da Maghrib", true,
            ['Abdurrahman Hakim', 'Muhammad Fauzan Akbar', 'Haikal Fadhlurrahman', 'Ahmad Zaki Mubarak', 'Faris Al-Ghifari']],
        ['Halaqah Zaid bin Tsabit', 'SLTA', 'MAK-2019-010', 'L', "Ba'da Subuh dan Ba'da Isya", true,
            ['Abdul Malik Firdaus', 'Hasbi Ramadhani']],
        ['Halaqah Aisyah', null, 'MAK-2020-020', 'P', null, false, []],
    ];

    /**
     * Setoran: [santri, hari lalu, sesi, jenis, surah:ayat awal, surah:ayat akhir, halaman, nilai, kesalahan, catatan].
     * Abdurrahman menuntaskan juz 30 secara mundur (An-Nas ke An-Naba').
     */
    private const RECORDS = [
        ['Abdurrahman Hakim', 9, 'subuh', 'ziyadah', [100, 1], [114, 6], 3.0, 'mumtaz', 0, null],
        ['Abdurrahman Hakim', 7, 'subuh', 'ziyadah', [90, 1], [99, 8], 4.0, 'jayyid_jiddan', 1, null],
        ['Abdurrahman Hakim', 5, 'maghrib', 'ziyadah', [78, 1], [89, 30], 13.0, 'jayyid', 3, 'Perbaiki mad pada akhir ayat.'],
        ['Abdurrahman Hakim', 3, 'maghrib', 'murajaah', [78, 1], [114, 6], 20.0, 'jayyid_jiddan', 2, 'Siap ujian kenaikan juz.'],
        ['Muhammad Fauzan Akbar', 6, 'subuh', 'ziyadah', [78, 1], [78, 40], 1.5, 'jayyid', 2, null],
        ['Muhammad Fauzan Akbar', 3, 'subuh', 'ziyadah', [79, 1], [79, 46], 1.5, 'jayyid_jiddan', 1, null],
        ['Muhammad Fauzan Akbar', 1, 'maghrib', 'murajaah', [78, 1], [78, 40], 1.5, 'mumtaz', 0, null],
        ['Haikal Fadhlurrahman', 4, 'subuh', 'ziyadah', [87, 1], [88, 26], 1.5, 'mumtaz', 0, null],
        ['Haikal Fadhlurrahman', 2, 'subuh', 'ziyadah', [89, 1], [89, 30], 1.5, 'ulang', 6, 'Diulang pada setoran berikutnya.'],
        ['Ahmad Zaki Mubarak', 2, 'maghrib', 'ziyadah', [112, 1], [114, 6], 0.5, 'jayyid', 1, null],
        ['Faris Al-Ghifari', 1, 'subuh', 'ziyadah', [110, 1], [114, 6], 1.0, 'jayyid_jiddan', 0, null],
        ['Abdul Malik Firdaus', 3, 'subuh', 'ziyadah', [67, 1], [67, 30], 2.5, 'jayyid', 2, null],
        ['Hasbi Ramadhani', 2, 'subuh', 'ziyadah', [78, 1], [78, 20], 1.0, 'jayyid_jiddan', 1, null],
    ];

    public function __construct(private readonly DemoContext $context) {}

    public function run(): void
    {
        $admin = $this->context->user('super-admin');
        $teacher = $this->context->user('ustadz-tahfidz');

        foreach (self::HALAQAHS as [$name, $unitCode, $nip, $gender, $schedule, $isActive, $members]) {
            $halaqah = Halaqah::withoutUnitScope()->firstOrCreate(
                ['academic_year_id' => $this->context->activeYear->id, 'name' => $name],
                [
                    'unit_id' => $unitCode === null ? null : $this->context->unit($unitCode)->id,
                    'teacher_staff_id' => $this->context->staff[$nip]->id,
                    'gender' => $gender,
                    'schedule' => $schedule,
                    'is_active' => $isActive,
                ],
            );

            foreach ($members as $student) {
                app(AddHalaqahMember::class)->handle($admin, $halaqah, $this->context->student($student), $this->joinedOn());
            }

            if ($members === [] || MemorizationRecord::withoutUnitScope()->where('halaqah_id', $halaqah->id)->exists()) {
                continue;
            }

            $actor = $halaqah->teacher_staff_id === $teacher->staff?->id ? $teacher : $admin;
            $this->seedRecords($actor, $halaqah, $members);

            if ($name === 'Halaqah Abu Hurairah') {
                $this->seedExam($teacher, $halaqah);
            }
        }
    }

    /**
     * @param  list<string>  $members
     */
    private function seedRecords(User $actor, Halaqah $halaqah, array $members): void
    {
        foreach (self::RECORDS as [$student, $daysAgo, $session, $type, $from, $to, $pages, $grade, $mistakes, $note]) {
            if (! in_array($student, $members, true)) {
                continue;
            }

            app(RecordMemorization::class)->handle($actor, [
                'student_id' => $this->context->student($student)->id,
                'halaqah_id' => $halaqah->id,
                'recorded_on' => $this->dateDaysAgo($daysAgo),
                'session' => $session,
                'type' => $type,
                'from_surah_id' => $from[0],
                'from_ayah' => $from[1],
                'to_surah_id' => $to[0],
                'to_ayah' => $to[1],
                'pages' => $pages,
                'grade' => $grade,
                'mistakes' => $mistakes,
                'note' => $note,
                'listener_staff_id' => $halaqah->teacher_staff_id,
            ]);
        }
    }

    /**
     * Ujian kenaikan juz 30 Abdurrahman, diuji ustadz lain dan lulus dengan syahadah.
     */
    private function seedExam(User $actor, Halaqah $halaqah): void
    {
        $examiner = $this->context->staff['MAK-2016-002'];
        $number = 'SYH/'.$this->context->calendar->today->year.'/001';

        if (TahfidzExam::withoutUnitScope()->where('certificate_no', $number)->exists()) {
            return;
        }

        app(RecordTahfidzExam::class)->handle($actor, [
            'student_id' => $this->context->student('Abdurrahman Hakim')->id,
            'halaqah_id' => $halaqah->id,
            'kind' => 'kenaikan_juz',
            'juz_from' => 30,
            'juz_to' => 30,
            'exam_on' => $this->dateDaysAgo(1),
            'score' => 88,
            'result' => 'lulus',
            'certificate_no' => $number,
            'examiner_staff_id' => $examiner->id,
        ]);
    }

    /**
     * Awal tahun ajaran aktif; setoran tidak pernah sebelum tanggal ini.
     */
    private function joinedOn(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->context->activeYear->starts_on->toDateString());
    }

    private function dateDaysAgo(int $days): string
    {
        return max($this->context->calendar->daysAgo($days), $this->joinedOn())->toDateString();
    }
}
