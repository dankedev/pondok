<?php

namespace Database\Seeders\Demo;

use App\Actions\Perizinan\CancelLeaveRequest;
use App\Actions\Perizinan\CheckInLeaveRequest;
use App\Actions\Perizinan\CheckOutLeaveRequest;
use App\Actions\Perizinan\DecideLeaveRequest;
use App\Actions\Perizinan\RecordVisit;
use App\Actions\Perizinan\SaveHomecomingPeriod;
use App\Actions\Perizinan\SubmitLeaveRequest;
use App\Models\AcademicYear;
use App\Models\HomecomingPeriod;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Student;
use App\Models\User;
use App\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Perizinan santri (F024): periode libur Idul Fitri, izin dengan berbagai status, dan kunjungan wali.
 * Semua lewat Action sehingga aturan transisi dan activity log berlaku. Waktu tiap langkah diatur
 * sementara (`Carbon::setTestNow`) agar jam keluar/kembali dan log tampak wajar, lalu dikembalikan.
 * Izin dan kunjungan hanya dibuat bila tabelnya masih kosong (idempotent).
 *
 * Periode pulang massal sengaja tidak di-generate: izin PULANG `disetujui` untuk seluruh santri akan
 * menutup izin lain sampai libur tiba. Admin bisa mencoba aksi "Buat izin pulang massal" sendiri.
 */
final class DemoLeave
{
    public const PERIOD_NAME = 'Libur Idul Fitri 1448 H';

    /** 1 Syawal 1448 H diperkirakan 9 Maret 2027; libur dimulai akhir Ramadhan. */
    private const PERIOD_DEPART_ON = '2027-03-05';

    private const PERIOD_RETURN_ON = '2027-03-21';

    /**
     * Izin: pengaju (`kesantrian` memakai nama santri SLTA, `musyrif` memakai urutan santri binaannya),
     * jenis, asal permintaan, alasan, rencana keluar dan batas kembali [hari dari hari ini, jam],
     * lalu langkah [aksi, hari dari hari ini, jam, catatan]. Hari negatif = lalu.
     */
    private const LEAVES = [
        [
            'by' => 'kesantrian', 'student' => 'Muhammad Alif Furqon', 'type' => 'KEPERLUAN', 'via' => 'wali',
            'reason' => 'Menghadiri walimah pernikahan kakak kandung di kampung.',
            'depart' => [-12, '08:00'], 'due' => [-10, '17:00'],
            'steps' => [['submit', -14, '09:30'], ['approve', -13, '15:00'], ['out', -12, '08:10'], ['in', -10, '16:20']],
        ],
        [
            'by' => 'musyrif', 'student' => 0, 'type' => 'KELUAR', 'via' => 'santri',
            'reason' => 'Membeli kitab dan perlengkapan kamar di toko dekat pondok.',
            'depart' => [-5, '13:00'], 'due' => [-5, '17:00'],
            'steps' => [['submit', -5, '07:30'], ['approve', -5, '11:00'], ['out', -5, '13:05'], ['in', -5, '17:40']],
        ],
        [
            'by' => 'kesantrian', 'student' => 'Zaidan Ar-Rasyid', 'type' => 'SAKIT', 'via' => 'wali',
            'reason' => 'Kontrol gigi ke Klinik Pratama, diantar ayah.',
            'depart' => [-1, '08:00'], 'due' => [-1, '16:00'],
            'steps' => [['submit', -2, '19:30'], ['approve', -2, '20:00'], ['out', -1, '08:05']],
        ],
        [
            'by' => 'kesantrian', 'student' => 'Ridho Kamaluddin', 'type' => 'KEPERLUAN', 'via' => 'wali',
            'reason' => 'Mengurus perekaman KTP di Disdukcapil bersama ayah.',
            'depart' => [1, '07:00'], 'due' => [2, '17:00'],
            'steps' => [['submit', -2, '10:00'], ['approve', -1, '14:00']],
        ],
        [
            'by' => 'musyrif', 'student' => 1, 'type' => 'KELUAR', 'via' => 'santri',
            'reason' => 'Potong rambut dan membeli sandal.',
            'depart' => [1, '13:00'], 'due' => [1, '16:00'],
            'steps' => [['submit', -1, '20:00']],
        ],
        [
            'by' => 'musyrif', 'student' => 2, 'type' => 'KELUAR', 'via' => 'santri',
            'reason' => 'Bermain ke rumah teman di desa sebelah.',
            'depart' => [-3, '14:00'], 'due' => [-3, '17:00'],
            'steps' => [['submit', -4, '20:00'], ['reject', -3, '06:30', "Tidak ada keperluan mendesak dan bertepatan dengan halaqah ba'da Ashar."]],
        ],
        [
            'by' => 'kesantrian', 'student' => 'Habib Nurrohman', 'type' => 'KEPERLUAN', 'via' => 'wali',
            'reason' => 'Acara keluarga besar di rumah nenek.',
            'depart' => [-6, '08:00'], 'due' => [-5, '17:00'],
            'steps' => [['submit', -9, '10:00'], ['approve', -8, '13:00'], ['cancel', -7, '09:15', 'Wali mengabarkan acara keluarga diundur.']],
        ],
    ];

    /**
     * Kunjungan: [santri, pakai wali santri (bila tidak, nama dan hubungan pengunjung), hari lalu, jam mulai, jam selesai, titipan].
     */
    private const VISITS = [
        ['Muhammad Fauzan Akbar', null, -11, '10:00', '11:30', 'Kurma 1 kg, susu UHT 1 dus, dan 5 buku tulis.'],
        ['Najmi Humaidi', null, -4, '09:30', '10:45', 'Pakaian ganti dan obat maag dari puskesmas.'],
        ['Kamil Shalih', ['Hasan Effendi', 'Paman'], -4, '13:15', '14:00', null],
    ];

    public function __construct(private readonly DemoContext $context) {}

    public function run(): void
    {
        $this->seedPeriod();

        if (! LeaveRequest::withoutGlobalScopes()->exists()) {
            $this->seedLeaves();
        }

        if (! Visit::withoutGlobalScopes()->exists()) {
            $this->seedVisits();
        }
    }

    private function seedPeriod(): void
    {
        if (HomecomingPeriod::query()->where('name', self::PERIOD_NAME)->exists()) {
            return;
        }

        $year = AcademicYear::query()
            ->whereDate('starts_on', '<=', self::PERIOD_DEPART_ON)
            ->whereDate('ends_on', '>=', self::PERIOD_DEPART_ON)
            ->first() ?? $this->context->activeYear;

        app(SaveHomecomingPeriod::class)->handle([
            'academic_year_id' => $year->id,
            'name' => self::PERIOD_NAME,
            'depart_on' => self::PERIOD_DEPART_ON,
            'return_on' => self::PERIOD_RETURN_ON,
        ], $this->context->user('kesantrian'));
    }

    private function seedLeaves(): void
    {
        $types = LeaveType::query()->pluck('id', 'code');

        foreach (self::LEAVES as $leave) {
            $actor = $this->context->user($leave['by']);
            $student = is_int($leave['student'])
                ? Student::visibleStudentsFor($actor)->active()->orderBy('name')->skip($leave['student'])->first()
                : $this->context->student($leave['student']);

            if (! $student instanceof Student || ! $types->has($leave['type'])) {
                $this->context->skip('perizinan', "santri atau jenis izin {$leave['type']} untuk akun {$leave['by']} tidak ditemukan");

                continue;
            }

            $request = null;

            foreach ($leave['steps'] as $step) {
                $request = $this->at($step[1], $step[2], fn (): LeaveRequest => $this->step($step[0], $request, $actor, $step[3] ?? null, [
                    'student_id' => $student->id,
                    'leave_type_id' => $types[$leave['type']],
                    'requested_via' => $leave['via'],
                    'reason' => $leave['reason'],
                    'planned_departure' => $this->moment(...$leave['depart'])->toDateTimeString(),
                    'return_due' => $this->moment(...$leave['due'])->toDateTimeString(),
                    'pickup_name' => $leave['via'] === 'wali' ? $student->guardians()->first()?->name : null,
                ]));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function step(string $action, ?LeaveRequest $request, User $requester, ?string $note, array $data): LeaveRequest
    {
        $guard = $this->context->user('petugas-pos');

        return match ($action) {
            'submit' => app(SubmitLeaveRequest::class)->handle($requester, $data),
            'approve' => app(DecideLeaveRequest::class)->handle($request, $requester, true, $note),
            'reject' => app(DecideLeaveRequest::class)->handle($request, $requester, false, $note),
            'out' => app(CheckOutLeaveRequest::class)->handle($request, $guard),
            'in' => app(CheckInLeaveRequest::class)->handle($request, $guard),
            'cancel' => app(CancelLeaveRequest::class)->handle($request, $requester, $note),
        };
    }

    private function seedVisits(): void
    {
        $guard = $this->context->user('petugas-pos');

        foreach (self::VISITS as [$name, $visitor, $day, $start, $end, $items]) {
            $student = $this->context->student($name);
            $guardian = $visitor === null ? $student->guardians()->first() : null;

            if ($visitor === null && $guardian === null) {
                $this->context->skip('kunjungan', "wali {$name} tidak ditemukan");

                continue;
            }

            $this->at($day, $start, fn (): Visit => app(RecordVisit::class)->handle($guard, [
                'student_id' => $student->id,
                'guardian_id' => $guardian?->id,
                'visitor_name' => $guardian->name ?? $visitor[0],
                'relation' => $guardian === null ? $visitor[1] : $guardian->pivot->relation,
                'started_at' => $this->moment($day, $start)->toDateTimeString(),
                'ended_at' => $this->moment($day, $end)->toDateTimeString(),
                'items_note' => $items,
            ]));
        }
    }

    private function moment(int $day, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $this->context->calendar->today->addDays($day)->setTime($hour, $minute);
    }

    /**
     * Menjalankan `$callback` seolah-olah pada waktu demo itu, lalu mengembalikan waktu sebelumnya.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function at(int $day, string $time, callable $callback): mixed
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow($this->moment($day, $time));

        try {
            return $callback();
        } finally {
            Carbon::setTestNow($previous);
        }
    }
}
