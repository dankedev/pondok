<?php

use App\Models\AcademicYear;
use App\Models\AdmissionWave;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\DemoData;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| F034 DemoSeeder
|--------------------------------------------------------------------------
| Seeding penuh hanya 3 kali di file ini (2 di test utama, 1 di test T2).
| Kontrak DemoData yang dibaca test: lihat laporan RED F034.
*/

const DEMO_ACCOUNTS = [
    'super-admin' => [],
    'bendahara' => [],
    'admin-sekolah' => ['SLTP'],
    'admin-psb' => [],
    'admin-konten' => [],
    'kesantrian' => ['SLTP', 'SLTA'],
    'musyrif' => [],
    'ustadz-tahfidz' => [],
    'petugas-pos' => [],
];

/**
 * Hitungan baris tabel data demo (tanpa activity_log) untuk pembandingan idempotensi.
 *
 * @return array<string, int>
 */
function demoTableCounts(): array
{
    $tables = ['units', 'academic_years', 'settings', 'users', 'unit_user', 'model_has_roles', 'staff', 'cohorts', 'classrooms',
        'students', 'enrollments', 'guardians', 'student_guardian', 'dormitories', 'rooms', 'room_assignments',
        'dormitory_supervisors', 'fee_types', 'fee_rates', 'scholarships', 'fee_overrides', 'invoices', 'invoice_items',
        'payments', 'payment_allocations', 'admission_waves', 'admission_tracks', 'admission_track_fee_type',
        'admission_requirements', 'applicants', 'applicant_documents', 'documents', 'issued_letters', 'achievements',
        'achievement_student', 'categories', 'posts', 'albums', 'photos', 'videos', 'pages'];

    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = DB::table($table)->count();
    }

    return $counts;
}

function demoUnitId(string $code): int
{
    return (int) Unit::query()->where('code', $code)->value('id');
}

function demoYearId(string $name): int
{
    return (int) AcademicYear::query()->where('name', $name)->value('id');
}

function demoUser(string $role): User
{
    return User::query()->where('email', $role.'@demo.test')->firstOrFail();
}

function demoCheck(bool $condition, string $message): void
{
    test()->assertTrue($condition, $message);
}

it('AC-1: menolak berjalan di production tanpa menulis data', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(DemoSeeder::class)->run())
        ->toThrow(RuntimeException::class, __('pondok.demo.refused_production'));

    foreach (['students', 'staff', 'invoices', 'applicants'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
    expect(User::query()->where('email', 'like', '%@demo.test')->count())->toBe(0);
});

it('AC-2: tidak dipanggil oleh seeder instalasi', function () {
    config(['pondok.super_admin' => ['name' => 'Admin', 'email' => 'admin@pondok.test', 'password' => 'rahasia-12345']]);

    $this->seed(DatabaseSeeder::class);

    foreach (['students', 'staff', 'posts'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
    expect(User::query()->where('email', 'like', '%@demo.test')->count())->toBe(0);

    foreach (['DatabaseSeeder', 'InstallSeeder', 'SystemSeeder'] as $file) {
        expect(file_get_contents(database_path("seeders/{$file}.php")))->not->toContain('DemoSeeder');
    }
});

it('AC-24: dokumentasi demo dan env contoh tersedia', function () {
    expect(base_path('docs/demo.md'))->toBeFile();
    $doc = file_get_contents(base_path('docs/demo.md'));

    foreach (array_keys(DEMO_ACCOUNTS) as $role) {
        expect($doc)->toContain($role.'@demo.test');
    }
    expect($doc)->toContain('DEMO_PASSWORD')
        ->toContain(DemoSeeder::DEFAULT_PASSWORD)
        ->toContain('php artisan db:seed --class=DemoSeeder');
    expect(file_get_contents(base_path('.env.example')))->toContain('DEMO_PASSWORD=');
});

it('AC-3..AC-22: mengisi data demo lengkap, mematuhi aturan bisnis, dan idempotent', function () {
    $t1 = CarbonImmutable::parse('2026-10-16 10:00', 'Asia/Jakarta');
    $this->travelTo($t1);
    Storage::fake('private');
    Storage::fake('public');
    config(['pondok.demo.password' => 'rahasia-uji-123']);
    expect(Auth::check())->toBeFalse();

    // Seed pertama sebagai tamu (jalur artisan agar keluaran konsol bisa diperiksa).
    $this->artisan('db:seed', ['--class' => DemoSeeder::class])
        ->doesntExpectOutputToContain(__('pondok.demo.default_password_warning'))
        ->assertSuccessful();

    $today = '2026-10-16';
    $sltp = demoUnitId('SLTP');
    $slta = demoUnitId('SLTA');
    $yearP = demoYearId('2025/2026');
    $yearA = demoYearId('2026/2027');
    $yearN = demoYearId('2027/2028');

    // AC-3
    expect(Unit::query()->pluck('code')->sort()->values()->all())->toBe(['SD', 'SLTA', 'SLTP']);
    expect(Role::query()->whereIn('name', array_keys(DEMO_ACCOUNTS))->count())->toBe(9);
    foreach (['akademik', 'asrama', 'psb', 'keuangan', 'konten', 'dokumen', 'prestasi'] as $module) {
        expect(Setting::get("modules.{$module}.enabled"))->toBeTruthy("AC-3 modul {$module}");
    }

    // AC-4
    $years = AcademicYear::query()->get()->keyBy('name');
    demoCheck(isset($years['2025/2026'], $years['2026/2027'], $years['2027/2028']), 'AC-4 tiga tahun ajaran');
    expect($years['2025/2026']->starts_on->toDateString())->toBe('2025-07-01')
        ->and($years['2025/2026']->ends_on->toDateString())->toBe('2026-06-30');
    expect(AcademicYear::query()->where('is_active', true)->pluck('name')->all())->toBe(['2026/2027']);

    // AC-5
    expect(Setting::get('school.name'))->toBe("Ma'had Al-Khair Belitang");
    expect(Setting::get('school.address'))->toContain('Belitang');
    demoCheck(str_contains((string) Setting::get('school.address'), 'Ogan Komering Ulu Timur') || str_contains((string) Setting::get('school.address'), 'OKU Timur'), 'AC-5 alamat');
    demoCheck(filled(Setting::get('school.phone')) && filled(Setting::get('school.email')), 'AC-5 telepon dan email');
    foreach (['profil', 'visi-misi', 'kontak'] as $slug) {
        demoCheck(filled(DB::table('pages')->where('slug', $slug)->value('body')), "AC-5 halaman {$slug}");
    }
    foreach ([['posts', 'body'], ['posts', 'title'], ['pages', 'body']] as [$table, $column]) {
        demoCheck(DB::table($table)->where($column, 'like', '%lorem%')->count() === 0, "AC-5 lorem di {$table}.{$column}");
    }

    // AC-6 (bagian data)
    $demoUsers = User::query()->where('email', 'like', '%@demo.test')->get();
    expect($demoUsers)->toHaveCount(9);
    foreach (DEMO_ACCOUNTS as $role => $unitCodes) {
        $user = demoUser($role);
        expect($user->roles->pluck('name')->all())->toBe([$role]);
        demoCheck((bool) $user->is_active && $user->email_verified_at !== null, "AC-6 {$role} aktif dan terverifikasi");
        $expected = collect($unitCodes)->map(fn ($c) => demoUnitId($c))->sort()->values()->all();
        expect($user->scopedUnitIds())->toEqualCanonicalizing($expected);
    }
    foreach (['musyrif', 'ustadz-tahfidz', 'bendahara'] as $role) {
        expect(DB::table('staff')->where('user_id', demoUser($role)->id)->count())->toBe(1);
    }

    // AC-7a
    foreach ($demoUsers as $user) {
        demoCheck(Hash::check('rahasia-uji-123', $user->password), "AC-7a {$user->email}");
    }

    // AC-8
    $staff = DB::table('staff')->where('is_active', true)->get();
    demoCheck($staff->count() >= 22, 'AC-8 staff aktif >= 22');
    foreach (['ustadz', 'musyrif', 'TU'] as $position) {
        demoCheck($staff->contains('position', $position), "AC-8 posisi {$position}");
    }
    $classroomsA = DB::table('classrooms')->where('academic_year_id', $yearA)->get();
    expect($classroomsA)->toHaveCount(15);
    expect(DB::table('classrooms')->where('academic_year_id', $yearP)->count())->toBe(15);
    expect($classroomsA->where('unit_id', demoUnitId('SD'))->where('gender', 'campur'))->toHaveCount(3);
    foreach ([$sltp, $slta] as $unitId) {
        expect($classroomsA->where('unit_id', $unitId)->where('gender', 'L'))->toHaveCount(3);
        expect($classroomsA->where('unit_id', $unitId)->where('gender', 'P'))->toHaveCount(3);
    }
    foreach ($classroomsA as $classroom) {
        demoCheck($classroom->homeroom_staff_id !== null, "AC-8 wali kelas {$classroom->name}");
        if ($classroom->gender === 'P') {
            expect(DB::table('staff')->where('id', $classroom->homeroom_staff_id)->value('gender'))->toBe('P');
        }
    }

    // AC-9
    $students = Student::withoutUnitScope()->withTrashed()->get();
    demoCheck($students->count() >= 60 && $students->count() <= 100, 'AC-9 jumlah santri 60-100');
    $baseYear = 2026;
    $demoNis = collect(DemoData::students())->map(fn (array $row): string => ($baseYear + $row['entry_offset']).str_pad((string) $row['seq'], 4, '0', STR_PAD_LEFT));
    $demoNames = collect(DemoData::students())->pluck('name');
    $nonPsb = $students->whereNull('applicant_id');
    expect($nonPsb->pluck('name')->diff($demoNames)->all())->toBe([]);
    expect($demoNis->diff($students->pluck('nis'))->all())->toBe([]);
    expect($nonPsb)->toHaveCount($demoNis->count());
    $byStatus = $students->groupBy('status')->map->count();
    demoCheck($byStatus->get('aktif', 0) >= 75 && $byStatus->get('lulus', 0) >= 5 && $byStatus->get('keluar', 0) >= 1 && $byStatus->get('pindah', 0) >= 1, 'AC-9 komposisi status');
    foreach (['SD', 'SLTP', 'SLTA'] as $code) {
        foreach (['L', 'P'] as $gender) {
            demoCheck($students->where('status', 'aktif')->where('unit_id', demoUnitId($code))->where('gender', $gender)->isNotEmpty(), "AC-9 {$code} {$gender} aktif");
        }
    }
    $contacts = DB::table('student_guardian')->where('is_billing_contact', true)->groupBy('student_id')->selectRaw('student_id, count(*) as c')->pluck('c', 'student_id');
    foreach ($students as $student) {
        demoCheck(($contacts[$student->id] ?? 0) === 1, "AC-9 kontak tagihan santri {$student->nis}");
    }
    $familySizes = DB::table('student_guardian')->groupBy('guardian_id')->selectRaw('count(*) as c')->pluck('c');
    demoCheck($familySizes->filter(fn ($c) => $c >= 2)->count() >= 8 && $familySizes->max() >= 3, 'AC-9 keluarga bersaudara');
    expect(DB::table('students')->join('cohorts', 'cohorts.id', '=', 'students.cohort_id')->whereColumn('cohorts.unit_id', '!=', 'students.unit_id')->count())->toBe(0);

    // AC-10
    $enrollments = DB::table('enrollments')
        ->join('classrooms', 'classrooms.id', '=', 'enrollments.classroom_id')
        ->select('enrollments.student_id', 'enrollments.academic_year_id', 'classrooms.grade', 'classrooms.unit_id as classroom_unit')
        ->get();
    $enrolledA = $enrollments->where('academic_year_id', $yearA)->keyBy('student_id');
    $enrolledP = $enrollments->where('academic_year_id', $yearP)->keyBy('student_id');
    $cohortYear = DB::table('cohorts')->pluck('entry_year', 'id');
    foreach ($students->where('status', 'aktif') as $student) {
        if ($cohortYear[$student->cohort_id] <= 2026) {
            demoCheck($enrolledA->has($student->id), "AC-10 enrollment A {$student->nis}");
        }
    }
    foreach ($students->where('status', '!=', 'aktif') as $student) {
        demoCheck(! $enrolledA->has($student->id), "AC-10 non-aktif tanpa enrollment A {$student->nis}");
    }
    $promoted = $enrolledP->filter(fn ($p, $id) => $enrolledA->has($id) && $enrolledA[$id]->grade === $p->grade + 1);
    demoCheck($promoted->count() >= 20, 'AC-10 kenaikan kelas >= 20');
    demoCheck($enrolledP->contains(fn ($p, $id) => $p->classroom_unit === demoUnitId('SD') && $enrolledA->has($id) && $enrolledA[$id]->classroom_unit === $sltp), 'AC-10 SD ke SLTP');
    demoCheck($enrolledP->contains(fn ($p, $id) => $p->classroom_unit === $sltp && $enrolledA->has($id) && $enrolledA[$id]->classroom_unit === $slta), 'AC-10 SLTP ke SLTA');
    foreach ($enrolledA as $studentId => $row) {
        demoCheck($row->classroom_unit === $students->firstWhere('id', $studentId)->unit_id, "AC-10 jenjang rombel A santri {$studentId}");
    }

    // AC-11
    $dormitories = DB::table('dormitories')->where('is_active', true)->get();
    expect($dormitories->pluck('gender')->sort()->values()->all())->toBe(['L', 'P']);
    expect(DB::table('rooms')->count())->toBe(8);
    $active = DB::table('room_assignments')->whereNull('end_on')
        ->join('rooms', 'rooms.id', '=', 'room_assignments.room_id')
        ->join('dormitories', 'dormitories.id', '=', 'rooms.dormitory_id')
        ->select('room_assignments.student_id', 'room_assignments.room_id', 'rooms.capacity', 'dormitories.gender as dorm_gender')
        ->get();
    demoCheck($active->count() >= 40, 'AC-11 penempatan aktif >= 40');
    foreach ($active as $row) {
        $student = $students->firstWhere('id', $row->student_id);
        demoCheck($student->status === 'aktif' && $student->unit_id !== demoUnitId('SD') && $student->gender === $row->dorm_gender, "AC-11 penempatan sah santri {$student->nis}");
    }
    foreach ($active->groupBy('room_id') as $rows) {
        demoCheck($rows->count() <= $rows->first()->capacity, 'AC-11 kapasitas kamar');
    }
    demoCheck(DB::table('room_assignments')->groupBy('student_id')->selectRaw('student_id, count(*) as c, count(end_on) as closed')->get()->contains(fn ($r) => $r->c >= 2 && $r->closed >= 1), 'AC-11 pindah kamar');
    foreach ($dormitories as $dormitory) {
        $rows = DB::table('dormitory_supervisors')->where('dormitory_id', $dormitory->id)->where('academic_year_id', $yearA)->whereNull('end_on')->get();
        expect($rows->where('role', 'kepala_asrama'))->toHaveCount(1);
        demoCheck($rows->where('role', 'musyrif')->count() >= 1, 'AC-11 musyrif asrama');
    }

    // AC-12
    $expectedTypes = [
        'SPP' => ['bulanan', 'semua', 'BULANAN', 30], 'ASRAMA' => ['bulanan', 'mukim', 'BULANAN', 20], 'MAKAN' => ['bulanan', 'mukim', 'BULANAN', 10],
        'FORMULIR' => ['saat_daftar', 'semua', 'PSB_DAFTAR', 10], 'UANG_PANGKAL' => ['saat_diterima', 'semua', 'PSB_TERIMA', 30],
        'SERAGAM' => ['saat_diterima', 'semua', 'PSB_TERIMA', 20], 'KITAB' => ['saat_diterima', 'semua', 'PSB_TERIMA', 10],
    ];
    $types = DB::table('fee_types')->get()->keyBy('code');
    foreach ($expectedTypes as $code => [$frequency, $appliesTo, $group, $priority]) {
        demoCheck(isset($types[$code]) && [$types[$code]->frequency, $types[$code]->applies_to, $types[$code]->billing_group, (int) $types[$code]->priority] === [$frequency, $appliesTo, $group, $priority], "AC-12 jenis biaya {$code}");
    }
    $rates = DB::table('fee_rates')->get();
    foreach ($rates as $rate) {
        demoCheck($rate->amount % 1000 === 0 && $rate->amount >= 50000 && $rate->amount <= 5000000, "AC-12 nominal tarif {$rate->amount}");
    }
    demoCheck($rates->whereNotNull('cohort_id')->isNotEmpty() && $rates->whereNotNull('dormitory_id')->isNotEmpty(), 'AC-12 tarif angkatan dan asrama');
    demoCheck($rates->whereNull('cohort_id')->whereNull('dormitory_id')->every(fn ($r) => (int) $r->effective_from_year_id === $yearP), 'AC-12 tarif dasar mulai P');

    // AC-13
    $scholarships = DB::table('scholarships')->where('is_active', true)->get();
    expect($scholarships->pluck('mode')->sort()->values()->all())->toBe(['nominal', 'persen', 'potongan']);
    $overrides = DB::table('fee_overrides')->get();
    demoCheck($overrides->whereNotNull('student_id')->pluck('mode')->unique()->count() === 3, 'AC-13 tiga mode override');
    demoCheck($overrides->whereNull('scholarship_id')->isNotEmpty(), 'AC-13 override tanpa program');
    demoCheck($overrides->whereNotNull('student_id')->whereNull('applicant_id')->every(fn ($o) => (int) $o->approved_by === demoUser('bendahara')->id), 'AC-13 disetujui bendahara');
    demoCheck($overrides->whereNotNull('applicant_id')->every(fn ($o) => in_array((int) $o->approved_by, [demoUser('admin-psb')->id, demoUser('bendahara')->id], true)), 'AC-13 keringanan jalur PSB disetujui aktor penerima');

    // AC-14
    $mukimIds = DB::table('room_assignments')->whereNull('end_on')->pluck('student_id')->all();
    $items = DB::table('invoice_items')->join('fee_types', 'fee_types.id', '=', 'invoice_items.fee_type_id')
        ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
        ->where('invoices.billing_group', 'BULANAN')->where('invoices.status', '!=', 'batal')
        ->select('invoices.student_id', 'invoices.period', 'fee_types.code', 'invoice_items.*')->get();
    foreach (['2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01'] as $period) {
        $endOfPeriod = CarbonImmutable::parse($period)->endOfMonth()->toDateString();
        $invoices = DB::table('invoices')->where('billing_group', 'BULANAN')->where('status', '!=', 'batal')->whereDate('period', $period)->get();
        $eligible = $students->where('status', 'aktif')->filter(fn ($s) => $s->entered_on->toDateString() <= $endOfPeriod)->count();
        expect($invoices->count())->toBe($eligible, "AC-14 jumlah tagihan {$period}");
        expect($invoices->unique('student_id')->count())->toBe($invoices->count());
    }
    foreach ($items->groupBy('student_id') as $studentId => $studentItems) {
        $codes = $studentItems->pluck('code')->unique();
        if (in_array($studentId, $mukimIds, true)) {
            demoCheck($codes->contains('ASRAMA') && $codes->contains('MAKAN'), "AC-14 mukim {$studentId}");
        } else {
            demoCheck(! $codes->contains('ASRAMA') && ! $codes->contains('MAKAN'), "AC-14 nonmukim {$studentId}");
        }
    }
    demoCheck($items->where('code', 'SPP')->whereNotNull('fee_override_id')->every(fn ($i) => $i->discount_amount > 0), 'AC-14 diskon item SPP');
    $yatimIds = DB::table('fee_overrides')->join('scholarships', 'scholarships.id', '=', 'fee_overrides.scholarship_id')->where('scholarships.name', 'Beasiswa Yatim')->pluck('fee_overrides.student_id');
    demoCheck($yatimIds->isNotEmpty() && $items->where('code', 'SPP')->whereIn('student_id', $yatimIds)->every(fn ($i) => $i->amount === 0), 'AC-14 SPP yatim gratis');
    $sltaY = $students->where('unit_id', $slta)->filter(fn ($s) => $cohortYear[$s->cohort_id] === 2026)->pluck('id');
    $sppSlta = $items->where('code', 'SPP')->whereIn('student_id', $sltaY)->filter(fn ($i) => ! in_array($i->student_id, $yatimIds->all(), true) && $i->fee_override_id === null);
    demoCheck($sppSlta->isNotEmpty() && $sppSlta->every(fn ($i) => $i->base_amount === 325000), 'AC-14 tarif angkatan SLTA 2026');
    $khdIds = DB::table('room_assignments')->whereNull('end_on')->join('rooms', 'rooms.id', '=', 'room_assignments.room_id')->join('dormitories', 'dormitories.id', '=', 'rooms.dormitory_id')->where('dormitories.code', 'KHD')->pluck('student_id')->all();
    $asramaKhd = $items->where('code', 'ASRAMA')->filter(fn ($i) => in_array($i->student_id, $khdIds, true));
    demoCheck($asramaKhd->isNotEmpty() && $asramaKhd->every(fn ($i) => $i->base_amount === 225000), 'AC-14 tarif asrama KHD');

    // AC-15
    $monthly = DB::table('invoices')->where('billing_group', 'BULANAN')->where('status', '!=', 'batal')->get();
    demoCheck($monthly->where('status', 'lunas')->count() >= 100, 'AC-15 lunas >= 100');
    demoCheck($monthly->where('status', 'sebagian')->count() >= 5, 'AC-15 sebagian >= 5');
    demoCheck($monthly->where('status', 'belum')->filter(fn ($i) => $i->due_on < $today)->count() >= 10, 'AC-15 jatuh tempo belum bayar >= 10');
    $paidByInvoice = DB::table('payments')->where('status', 'diterima')->groupBy('invoice_id')->selectRaw('invoice_id, sum(amount) as s')->pluck('s', 'invoice_id');
    foreach (DB::table('invoices')->get() as $invoice) {
        expect((int) $invoice->paid_total)->toBe((int) ($paidByInvoice[$invoice->id] ?? 0), "AC-15 paid_total {$invoice->invoice_no}");
    }
    expect(DB::table('payments')->whereDate('paid_on', '>', $today)->count())->toBe(0);
    $cancelled = DB::table('payments')->where('status', 'batal')->get();
    expect($cancelled)->toHaveCount(1);
    demoCheck(filled($cancelled->first()->cancel_reason), 'AC-15 alasan batal');
    $itemsByInvoice = DB::table('invoice_items')->join('fee_types', 'fee_types.id', '=', 'invoice_items.fee_type_id')->select('invoice_items.*', 'fee_types.priority', 'fee_types.code')->get()->groupBy('invoice_id');
    $mealPaidSppUnpaid = false;
    foreach ($monthly->where('status', 'sebagian') as $invoice) {
        $rows = $itemsByInvoice[$invoice->id];
        foreach ($rows as $paidRow) {
            foreach ($rows as $other) {
                if ($paidRow->paid_amount > 0 && $other->priority < $paidRow->priority) {
                    demoCheck($other->paid_amount >= $other->amount, "AC-15 prioritas {$invoice->invoice_no}");
                }
            }
        }
        $meal = $rows->firstWhere('code', 'MAKAN');
        $spp = $rows->firstWhere('code', 'SPP');
        if ($meal && $spp && $meal->paid_amount === $meal->amount && $spp->amount > 0 && $spp->paid_amount === 0) {
            $mealPaidSppUnpaid = true;
        }
    }
    demoCheck($mealPaidSppUnpaid, 'AC-15 sebagian mukim: MAKAN lunas, SPP belum');

    // AC-16
    $wave = AdmissionWave::query()->where('name', 'Gelombang 1')->where('academic_year_id', $yearN)->firstOrFail();
    demoCheck($wave->isAcceptingRegistrationsOn($t1), 'AC-16 gelombang menerima pendaftaran');
    $applicants = DB::table('applicants')->get();
    expect($applicants)->toHaveCount(14);
    expect($applicants->groupBy('status')->map->count()->all())
        ->toEqualCanonicalizing(['cadangan' => 1, 'diajukan' => 4, 'diterima' => 5, 'diverifikasi' => 2, 'draft' => 1, 'ditolak' => 1]);
    foreach ($applicants->where('status', '!=', 'draft') as $applicant) {
        demoCheck(DB::table('invoices')->where('applicant_id', $applicant->id)->where('billing_group', 'PSB_DAFTAR')->exists(), "AC-16 tagihan daftar {$applicant->name}");
    }
    foreach ($applicants->where('status', 'ditolak') as $applicant) {
        expect(DB::table('invoices')->where('applicant_id', $applicant->id)->where('status', 'belum')->count())->toBe(0);
    }
    $converted = DB::table('students')->whereNotNull('applicant_id')->get();
    expect($converted)->toHaveCount(3);
    foreach ($converted as $student) {
        expect(DB::table('invoices')->where('applicant_id', $student->applicant_id)->whereNull('student_id')->count())->toBe(0);
        expect(DB::table('fee_overrides')->where('applicant_id', $student->applicant_id)->whereNull('student_id')->count())->toBe(0);
    }
    foreach (['Beasiswa Tahfidz', 'Beasiswa Yatim'] as $name) {
        $scholarshipId = DB::table('scholarships')->where('name', $name)->value('id');
        demoCheck(DB::table('fee_overrides')->whereNotNull('applicant_id')->where('scholarship_id', $scholarshipId)->exists(), "AC-16 override jalur {$name}");
    }
    $reusedGuardian = $converted->contains(fn ($s) => DB::table('student_guardian')->whereIn('guardian_id', DB::table('student_guardian')->where('student_id', $s->id)->select('guardian_id'))->count() >= 2);
    demoCheck($reusedGuardian, 'AC-16 wali dipakai ulang');
    demoCheck(DB::table('documents')->whereNotNull('source_applicant_document_id')->count() >= 2, 'AC-16 berkas pendaftar tersalin');
    foreach (['menunggu', 'ditolak'] as $status) {
        demoCheck(DB::table('applicant_documents')->where('status', $status)->exists(), "AC-16 berkas {$status}");
    }

    // AC-17
    $studentDocs = DB::table('documents')->where('documentable_type', (new Student)->getMorphClass())->get();
    demoCheck($studentDocs->where('status', 'valid')->count() >= 20 && $studentDocs->where('status', 'menunggu')->count() >= 5 && $studentDocs->where('status', 'ditolak')->count() >= 1, 'AC-17 status dokumen');
    demoCheck($studentDocs->whereNotNull('replaced_by_id')->isNotEmpty(), 'AC-17 dokumen diganti');
    demoCheck($studentDocs->whereNotNull('expires_on')->filter(fn ($d) => $d->expires_on >= $today && $d->expires_on <= '2026-12-15')->isNotEmpty(), 'AC-17 dokumen hampir kedaluwarsa');
    foreach (DB::table('documents')->pluck('file_path')->merge(DB::table('applicant_documents')->pluck('file_path')) as $path) {
        demoCheck(Storage::disk('private')->exists($path) && ! Storage::disk('public')->exists($path), "AC-17 berkas pribadi {$path}");
    }
    $withNumber = Document::query()->whereNotNull('number')->first();
    demoCheck($withNumber !== null && DB::table('documents')->where('id', $withNumber->id)->value('number') !== $withNumber->number, 'AC-17 nomor terenkripsi');
    demoCheck(DB::table('documents')->join('document_types', 'document_types.id', '=', 'documents.document_type_id')->where('document_types.code', 'SERTIFIKAT')->exists(), 'AC-17 dokumen prestasi');

    // AC-18
    $letters = DB::table('issued_letters')->get();
    expect($letters->where('status', 'terbit'))->toHaveCount(4);
    expect($letters->where('status', 'batal'))->toHaveCount(1);
    expect($letters->where('student_id', $letters->firstWhere('status', 'batal')->student_id))->toHaveCount(1);
    expect($letters->map(fn ($l) => $l->student_id.'|'.$l->type.'|'.$l->purpose)->duplicates()->all())->toBe([]);
    foreach (['keterangan_aktif', 'rekomendasi', 'kartu_santri'] as $type) {
        demoCheck($letters->contains('type', $type), "AC-18 jenis {$type}");
    }
    $mudirIds = DB::table('staff')->where('position', 'Mudir')->where('is_active', true)->pluck('id')->all();
    demoCheck($letters->every(fn ($l) => in_array($l->signed_by_staff_id, $mudirIds, true)), 'AC-18 penandatangan Mudir');
    expect($letters->pluck('letter_no')->unique())->toHaveCount($letters->count());

    // AC-19
    $achievements = DB::table('achievements')->get();
    expect($achievements)->toHaveCount(6);
    expect($achievements->whereNotNull('verified_by'))->toHaveCount(4);
    $participants = DB::table('achievement_student')->join('students', 'students.id', '=', 'achievement_student.student_id')->select('achievement_student.*', 'students.entered_on')->get();
    $team = $achievements->where('kind', 'beregu')->filter(fn ($a) => $participants->where('achievement_id', $a->id)->count() >= 3);
    demoCheck($team->count() >= 2, 'AC-19 prestasi beregu >= 3 peserta');
    demoCheck($participants->contains(fn ($p) => strtolower((string) $p->role) === 'ketua tim'), 'AC-19 ketua tim');
    $achievementPosts = DB::table('posts')->whereNotNull('achievement_id')->get();
    expect($achievementPosts)->toHaveCount(1);
    demoCheck($achievementPosts->first()->type === 'prestasi' && $achievementPosts->first()->status === 'terbit' && substr($achievementPosts->first()->published_at, 0, 10) <= $today, 'AC-19 berita prestasi');
    foreach ($participants as $participant) {
        $achievedOn = $achievements->firstWhere('id', $participant->achievement_id)->achieved_on;
        demoCheck(substr($participant->entered_on, 0, 10) <= substr($achievedOn, 0, 10), 'AC-19 peserta masuk sebelum prestasi');
    }

    // AC-20
    demoCheck(DB::table('categories')->where('type', 'post')->count() >= 3, 'AC-20 kategori berita');
    $posts = DB::table('posts')->get();
    demoCheck($posts->where('status', 'terbit')->filter(fn ($p) => substr($p->published_at, 0, 10) <= $today)->count() >= 8, 'AC-20 berita terbit >= 8');
    demoCheck($posts->where('status', 'draft')->count() >= 1, 'AC-20 draft');
    $albums = DB::table('albums')->get();
    expect($albums)->toHaveCount(3);
    foreach ($albums as $album) {
        $paths = DB::table('photos')->where('album_id', $album->id)->pluck('path');
        demoCheck($paths->count() >= 4 && $paths->every(fn ($p) => Storage::disk('public')->exists($p)), "AC-20 foto album {$album->slug}");
    }
    demoCheck($posts->whereNotNull('cover_path')->filter(fn ($p) => Storage::disk('public')->exists($p->cover_path))->count() >= 4, 'AC-20 sampul berita');
    expect(DB::table('videos')->count())->toBe(3);
    expect(DB::table('videos')->where('is_featured', true)->count())->toBe(1);
    $this->get(route('portal.home'))->assertOk()->assertSee("Ma'had Al-Khair Belitang");
    $this->get(route('portal.posts.index'))->assertOk();
    $this->get(route('portal.albums.index'))->assertOk();
    $this->get(route('portal.videos.index'))->assertOk();
    $this->get(route('portal.pages.show', 'profil'))->assertOk();

    // AC-7c dan AC-22: ubah password, snapshot, seed kedua saat admin-sekolah login.
    DB::table('users')->where('email', 'musyrif@demo.test')->update(['password' => Hash::make('sudah-diganti-1')]);
    $before = demoTableCounts();
    $sums = [DB::table('invoices')->sum('total'), DB::table('payments')->sum('amount')];
    $files = [count(Storage::disk('private')->allFiles()), count(Storage::disk('public')->allFiles())];

    $this->actingAs(demoUser('admin-sekolah'));
    $this->seed(DemoSeeder::class);

    expect(Auth::check())->toBeFalse();
    expect(demoTableCounts())->toBe($before);
    expect([DB::table('invoices')->sum('total'), DB::table('payments')->sum('amount')])->toBe($sums);
    expect([count(Storage::disk('private')->allFiles()), count(Storage::disk('public')->allFiles())])->toBe($files);
    demoCheck(Hash::check('sudah-diganti-1', demoUser('musyrif')->password), 'AC-7c password tidak dikembalikan');

    // AC-6 (cakupan jenjang) setelah seluruh seeding.
    $this->actingAs(demoUser('admin-sekolah'));
    $sltpTotal = Student::withoutUnitScope()->where('unit_id', $sltp)->count();
    expect(Student::query()->count())->toBe($sltpTotal);
    demoCheck($sltpTotal < Student::withoutUnitScope()->count(), 'AC-6 cakupan SLTP lebih kecil dari total');

    // AC-21
    $this->actingAs(demoUser('super-admin'));
    $this->get('/admin')->assertOk();
})->group('seeder-demo');

it('AC-23 dan AC-7b: tanggal mengikuti hari ini dan password bawaan dipakai bila env kosong', function () {
    $t2 = CarbonImmutable::parse('2027-07-03 08:00', 'Asia/Jakarta');
    $this->travelTo($t2);
    Storage::fake('private');
    Storage::fake('public');
    config(['pondok.demo.password' => null]);

    $this->artisan('db:seed', ['--class' => DemoSeeder::class])
        ->expectsOutputToContain(__('pondok.demo.default_password_warning'))
        ->assertSuccessful();

    expect(AcademicYear::query()->where('is_active', true)->pluck('name')->all())->toBe(['2027/2028']);
    demoCheck(AcademicYear::query()->where('name', '2026/2027')->where('is_active', false)->exists(), 'AC-23 tahun lalu tidak aktif');
    foreach (['2027-04-01', '2027-07-01'] as $period) {
        demoCheck(DB::table('invoices')->where('billing_group', 'BULANAN')->whereDate('period', $period)->exists(), "AC-23 tagihan {$period}");
    }
    expect(DB::table('payments')->whereDate('paid_on', '>', '2027-07-03')->count())->toBe(0);
    $wave = AdmissionWave::query()->where('academic_year_id', demoYearId('2028/2029'))->firstOrFail();
    demoCheck($wave->isAcceptingRegistrationsOn($t2), 'AC-23 gelombang menerima pendaftaran');
    expect(DB::table('posts')->where('published_at', '>', '2027-07-03 23:59:59')->count())->toBe(0);
    expect(DB::table('achievements')->whereDate('achieved_on', '>', '2027-07-03')->count())->toBe(0);

    foreach (User::query()->where('email', 'like', '%@demo.test')->get() as $user) {
        demoCheck(Hash::check(DemoSeeder::DEFAULT_PASSWORD, $user->password), "AC-7b {$user->email}");
    }
})->group('seeder-demo');

it('VERIFY: production tidak menulis berkas ke disk, tagihan hanya untuk santri aktif, uang bulat, dan dashboard semua akun render', function () {
    Storage::fake('private');
    Storage::fake('public');
    $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00', 'Asia/Jakarta'));

    app()->detectEnvironment(fn () => 'production');
    expect(fn () => app(DemoSeeder::class)->run())->toThrow(RuntimeException::class);
    expect(Storage::disk('private')->allFiles())->toBe([])->and(Storage::disk('public')->allFiles())->toBe([]);
    expect(DB::table('settings')->count())->toBe(0)->and(DB::table('units')->count())->toBe(0);
    app()->detectEnvironment(fn () => 'testing');

    config(['pondok.demo.password' => 'rahasia-uji-123']);
    $this->seed(DemoSeeder::class);

    // Santri lulus/keluar/pindah tidak ditagih bulanan pada tahun ajaran aktif.
    $inactive = Student::withoutUnitScope()->withTrashed()->where('status', '!=', 'aktif')->pluck('id');
    demoCheck($inactive->isNotEmpty(), 'VERIFY ada santri non-aktif');
    expect(DB::table('invoices')->whereIn('student_id', $inactive)->where('billing_group', 'BULANAN')->whereDate('period', '>=', '2026-07-01')->count())->toBe(0);

    // Item ASRAMA/MAKAN hanya untuk santri yang pernah punya penempatan kamar.
    $everHoused = DB::table('room_assignments')->pluck('student_id')->all();
    $mukimItems = DB::table('invoice_items')->join('fee_types', 'fee_types.id', '=', 'invoice_items.fee_type_id')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
        ->whereIn('fee_types.code', ['ASRAMA', 'MAKAN'])->pluck('invoices.student_id');
    demoCheck($mukimItems->isNotEmpty() && $mukimItems->every(fn ($id) => in_array($id, $everHoused, true)), 'VERIFY biaya mukim hanya untuk penghuni');

    // Uang bulat (integer) di semua kolom nominal.
    foreach ([['invoices', ['total', 'paid_total']], ['invoice_items', ['base_amount', 'discount_amount', 'amount', 'paid_amount']], ['payments', ['amount']], ['fee_rates', ['amount']]] as [$table, $columns]) {
        foreach (DB::table($table)->get($columns) as $row) {
            foreach ($columns as $column) {
                demoCheck(is_int($row->{$column}) && $row->{$column} >= 0, "VERIFY {$table}.{$column} bulat");
            }
        }
    }
    foreach (DB::table('invoice_items')->get() as $item) {
        demoCheck($item->amount === $item->base_amount - $item->discount_amount && $item->paid_amount <= $item->amount, 'VERIFY snapshot item konsisten');
    }

    // Data kurasi tanpa nama lembaga/tokoh nyata yang jelas.
    $curated = file_get_contents(database_path('seeders/Demo/DemoData.php'));
    foreach (['Gontor', 'Muhammadiyah', 'Nahdlatul', 'Kemenag', 'Kemendikbud', 'Presiden', 'Jokowi', 'Prabowo', 'Gus '] as $real) {
        expect($curated)->not->toContain($real);
    }

    // Dashboard panel untuk setiap akun demo tidak error; portal terbit tampil.
    foreach (array_keys(DEMO_ACCOUNTS) as $role) {
        Auth::forgetGuards();
        $this->flushSession();
        $this->app['session']->driver()->flush();
        $response = $this->actingAs(demoUser($role))->get('/admin');
        demoCheck($response->getStatusCode() === 200, "VERIFY dashboard {$role} status {$response->getStatusCode()} ke {$response->headers->get('Location')}");
    }
    Auth::forgetGuards();
    $this->get('/')->assertOk();
    $this->get('/berita')->assertOk()->assertSee('Penerimaan Santri Baru');

    // Cakupan jenjang kesantrian (SLTP + SLTA) tidak melihat SD.
    $this->actingAs(demoUser('kesantrian'));
    expect(Student::query()->where('unit_id', demoUnitId('SD'))->count())->toBe(0);
    expect(DB::table('invoices')->count())->toBeGreaterThan(Invoice::query()->count());
})->group('seeder-demo');
