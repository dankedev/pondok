<?php

use App\Actions\Konten\FormatPhoneForDisplay;
use App\Actions\Konten\StorePublicImage;
use App\Models\AcademicYear;
use App\Models\AdmissionWave;
use App\Models\Album;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Unit;
use App\Models\User;
use App\Models\Video;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\DemoData;
use Database\Seeders\DemoSeeder;
use Database\Seeders\InstallSeeder;
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
    'kesantrian' => ['SLTA'],
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

/**
 * Deret angka (telepon, rekening) dari brosur, diambil saat test berjalan:
 * angka 8 digit atau lebih setelah tanda hubung dan spasi dibuang.
 *
 * @return list<string>
 */
function demoBrochureNumbers(): array
{
    $text = (string) file_get_contents(base_path('docs/brand/brosur-psb-2027-2028.md'));
    preg_match_all('/\d[\d -]{6,}\d/', $text, $matches);

    return collect($matches[0])
        ->map(fn (string $raw): string => preg_replace('/\D/', '', $raw))
        ->filter(fn (string $digits): bool => strlen($digits) >= 8)
        ->unique()->values()->all();
}

/**
 * Pola nama orang dan usaha dari brosur. Ditulis literal karena baris brosur
 * tidak stabil untuk diekstrak (gelar dan jabatan menempel). Sumber:
 * docs/brand/brosur-psb-2027-2028.md baris Binaan, Alamat, dan Kontak.
 *
 * @return list<string>
 */
function demoBrochureForbiddenPatterns(): array
{
    return ['/\bWujud\b/u', '/Said Yai/u', '/Rendi Sulistiawan/u', '/Arwitra/u', '/Waterboom/u'];
}

/**
 * @param  array<string, list<string>>  $columnsByTable
 * @return list<string> lokasi yang cocok
 */
function demoScanColumns(array $columnsByTable, callable $matcher): array
{
    $hits = [];
    foreach ($columnsByTable as $table => $columns) {
        foreach ($columns as $column) {
            foreach (DB::table($table)->whereNotNull($column)->pluck($column) as $value) {
                if ($matcher((string) $value)) {
                    $hits[] = "{$table}.{$column}";
                }
            }
        }
    }

    return $hits;
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
    expect($doc)->toContain('migrate:fresh');
    $daftarUlangLine = collect(explode("\n", $doc))->first(fn (string $line) => stripos($line, 'DAFTAR_ULANG') !== false || stripos($line, 'daftar ulang') !== false);
    demoCheck($daftarUlangLine !== null && stripos($daftarUlangLine, 'contoh') !== false, 'AC-12 docs: baris daftar ulang bertanda contoh');
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
    expect(Setting::get('school.tagline'))->toBe("Generasi Beradab Bersama Al-Qur'an");
    foreach (['Mojosari', 'Belitang', 'OKU Timur'] as $part) {
        expect((string) Setting::get('school.address'))->toContain($part);
    }
    // F044: telepon kantor menjadi kontak "Kantor" bertanda cetak; school.phone tidak ada lagi.
    $printContacts = collect(Setting::get('school.contacts'))->where('is_print', true);
    demoCheck($printContacts->count() === 1 && $printContacts->first()['label'] === 'Kantor' && Setting::get('school.phone') === null && filled(Setting::get('school.email')), 'AC-5 kontak Kantor bertanda cetak dan email');
    // F037: konten profil dipecah ke halaman profil, pengurus, dan selayang-pandang.
    $profil = DB::table('pages')->whereIn('slug', ['profil', 'pengurus', 'selayang-pandang'])->get(['title', 'body'])->map(fn (object $row): string => $row->title.' '.$row->body)->implode(' ');
    foreach (['Tentang Yayasan', 'Selayang Pandang', 'Pengurus Al-Khair', 'khusus putra'] as $part) {
        demoCheck(str_contains($profil, $part), "AC-5 profil memuat {$part}");
    }
    $kontakBody = (string) DB::table('pages')->where('slug', 'kontak')->value('body');
    foreach (['Mojosari', 'info@alkhair-belitang.test', '0735', '0812 0000 0170'] as $retyped) {
        demoCheck(! str_contains($kontakBody, $retyped), "AC-8 halaman kontak tidak mengetik ulang {$retyped}");
    }
    demoCheck(str_contains($kontakBody, 'Jam layanan'), 'AC-8 halaman kontak memuat jam layanan');
    demoCheck(filled(DB::table('pages')->where('slug', 'visi-misi')->value('body')), 'AC-5 visi-misi');
    foreach ([['posts', 'body'], ['posts', 'title'], ['pages', 'body']] as [$table, $column]) {
        demoCheck(DB::table($table)->where($column, 'like', '%lorem%')->count() === 0, "AC-5 lorem di {$table}.{$column}");
    }

    // AC-25 (bagian 1)
    expect(Unit::query()->where('code', 'SLTP')->value('name'))->toBe('MTW/SMP');
    expect(Unit::query()->where('code', 'SLTA')->value('name'))->toBe('TSN/SMA');
    expect(Unit::query()->where('code', 'SD')->exists())->toBeTrue();

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
    foreach ($demoUsers as $user) {
        demoCheck(! in_array(demoUnitId('SD'), $user->scopedUnitIds(), true), "AC-6 {$user->email} tanpa cakupan SD");
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
    demoCheck($staff->count() >= 20, 'AC-8 staff aktif >= 20');
    foreach (['ustadz', 'musyrif', 'Mudir'] as $position) {
        demoCheck($staff->contains('position', $position), "AC-8 posisi {$position}");
    }
    foreach ($staff->whereIn('position', ['ustadz', 'musyrif', 'Mudir']) as $person) {
        demoCheck($person->gender === 'L', "AC-8 staff {$person->name} berjenis kelamin L");
    }
    $classroomsA = DB::table('classrooms')->where('academic_year_id', $yearA)->get();
    $classroomsP = DB::table('classrooms')->where('academic_year_id', $yearP)->get();
    foreach ([$classroomsA, $classroomsP] as $classrooms) {
        expect($classrooms)->toHaveCount(6);
        expect($classrooms->pluck('name')->sort()->values()->all())->toBe(['10A', '11A', '12A', '7A', '8A', '9A']);
        foreach ($classrooms as $classroom) {
            demoCheck($classroom->gender === 'L', "AC-8 rombel {$classroom->name} gender L");
            demoCheck($classroom->unit_id === ($classroom->grade <= 9 ? $sltp : $slta), "AC-8 jenjang rombel {$classroom->name}");
        }
    }
    foreach ($classroomsA as $classroom) {
        demoCheck($classroom->homeroom_staff_id !== null && DB::table('staff')->where('id', $classroom->homeroom_staff_id)->value('gender') === 'L', "AC-8 wali kelas {$classroom->name} laki-laki");
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
    demoCheck($students->every(fn ($s) => $s->gender === 'L'), 'AC-9 semua santri L');
    $sd = demoUnitId('SD');
    demoCheck($students->where('unit_id', $sd)->isEmpty(), 'AC-9 tanpa santri SD');
    foreach (['cohorts', 'classrooms', 'fee_rates', 'admission_requirements', 'applicants'] as $table) {
        demoCheck(DB::table($table)->where('unit_id', $sd)->count() === 0, "AC-9 tanpa {$table} SD");
    }
    $byStatus = $students->groupBy('status')->map->count();
    demoCheck($byStatus->get('aktif', 0) >= 65 && $byStatus->get('lulus', 0) >= 5 && $byStatus->get('keluar', 0) >= 1 && $byStatus->get('pindah', 0) >= 1, 'AC-9 komposisi status');
    foreach ([$sltp, $slta] as $unitId) {
        demoCheck($students->where('status', 'aktif')->where('unit_id', $unitId)->isNotEmpty(), 'AC-9 santri aktif per jenjang');
    }
    $contacts = DB::table('student_guardian')->where('is_billing_contact', true)->groupBy('student_id')->selectRaw('student_id, count(*) as c')->pluck('c', 'student_id');
    foreach ($students as $student) {
        demoCheck(($contacts[$student->id] ?? 0) === 1, "AC-9 kontak tagihan santri {$student->nis}");
    }
    $familySizes = DB::table('student_guardian')->groupBy('guardian_id')->selectRaw('count(*) as c')->pluck('c');
    demoCheck($familySizes->filter(fn ($c) => $c >= 2)->count() >= 5 && $familySizes->max() >= 3, 'AC-9 keluarga bersaudara');
    expect(DB::table('students')->join('cohorts', 'cohorts.id', '=', 'students.cohort_id')->whereColumn('cohorts.unit_id', '!=', 'students.unit_id')->count())->toBe(0);

    // AC-10
    $enrollments = DB::table('enrollments')
        ->join('classrooms', 'classrooms.id', '=', 'enrollments.classroom_id')
        ->select('enrollments.student_id', 'enrollments.academic_year_id', 'classrooms.grade', 'classrooms.unit_id as classroom_unit')
        ->get();
    $enrolledA = $enrollments->where('academic_year_id', $yearA)->keyBy('student_id');
    $enrolledP = $enrollments->where('academic_year_id', $yearP)->keyBy('student_id');
    $cohortYear = DB::table('cohorts')->pluck('entry_year', 'id');
    foreach ($students->where('status', 'aktif')->whereNull('applicant_id') as $student) {
        demoCheck($enrolledA->has($student->id), "AC-10 enrollment A {$student->nis}");
    }
    foreach ($students->where('status', '!=', 'aktif') as $student) {
        demoCheck(! $enrolledA->has($student->id), "AC-10 non-aktif tanpa enrollment A {$student->nis}");
    }
    $promoted = $enrolledP->filter(fn ($p, $id) => $enrolledA->has($id) && $enrolledA[$id]->grade === $p->grade + 1);
    demoCheck($promoted->count() >= 20, 'AC-10 kenaikan kelas >= 20');
    demoCheck($enrolledP->contains(fn ($p, $id) => $p->classroom_unit === $sltp && $enrolledA->has($id) && $enrolledA[$id]->classroom_unit === $slta), 'AC-10 SLTP ke SLTA');
    foreach ($enrolledA as $studentId => $row) {
        demoCheck($row->classroom_unit === $students->firstWhere('id', $studentId)->unit_id, "AC-10 jenjang rombel A santri {$studentId}");
    }

    // AC-11
    $dormitories = DB::table('dormitories')->where('is_active', true)->get();
    expect($dormitories)->toHaveCount(2);
    expect($dormitories->pluck('gender')->unique()->all())->toBe(['L']);
    expect($dormitories->pluck('code')->sort()->values()->all())->toBe(['ABK', 'UMR']);
    expect(DB::table('rooms')->count())->toBe(8);
    $active = DB::table('room_assignments')->whereNull('end_on')
        ->join('rooms', 'rooms.id', '=', 'room_assignments.room_id')
        ->select('room_assignments.student_id', 'room_assignments.room_id', 'rooms.capacity')
        ->get();
    $housed = $active->pluck('student_id')->all();
    foreach ($students->where('status', 'aktif') as $student) {
        if ($enrolledA->has($student->id)) {
            demoCheck(in_array($student->id, $housed, true), "AC-11 santri {$student->nis} mukim");
        } else {
            demoCheck($student->applicant_id !== null, "AC-11 nonmukim hanya hasil daftar ulang {$student->nis}");
        }
    }
    foreach ($active as $row) {
        demoCheck($students->firstWhere('id', $row->student_id)->status === 'aktif', 'AC-11 penghuni aktif');
    }
    foreach ($active->groupBy('room_id') as $rows) {
        demoCheck($rows->count() <= $rows->first()->capacity, 'AC-11 kapasitas kamar');
    }
    demoCheck(DB::table('room_assignments')->groupBy('student_id')->selectRaw('student_id, count(*) as c')->get()->contains(fn ($r) => $r->c >= 2), 'AC-11 pindah kamar');
    foreach (DB::table('rooms')->get() as $room) {
        $musyrif = DB::table('dormitory_supervisors')->where('room_id', $room->id)->where('role', 'musyrif')->whereNull('end_on')->get();
        expect($musyrif)->toHaveCount(1);
        expect(DB::table('staff')->where('id', $musyrif->first()->staff_id)->value('gender'))->toBe('L');
    }
    foreach ($dormitories as $dormitory) {
        expect(DB::table('dormitory_supervisors')->where('dormitory_id', $dormitory->id)->where('role', 'kepala_asrama')->whereNull('end_on')->count())->toBe(1);
    }

    // AC-12
    $expectedTypes = [
        'SPP' => ['bulanan', 'semua', 'BULANAN', 10], 'FORMULIR' => ['saat_daftar', 'semua', 'PSB_DAFTAR', 10],
        'UANG_MASUK' => ['saat_diterima', 'semua', 'PSB_TERIMA', 10], 'DAFTAR_ULANG' => ['tahunan', 'semua', 'DAFTAR_ULANG', 20],
    ];
    $types = DB::table('fee_types')->get()->keyBy('code');
    expect($types->keys()->sort()->values()->all())->toBe(['DAFTAR_ULANG', 'FORMULIR', 'SPP', 'UANG_MASUK']);
    foreach ($expectedTypes as $code => [$frequency, $appliesTo, $group, $priority]) {
        demoCheck([$types[$code]->frequency, $types[$code]->applies_to, $types[$code]->billing_group, (int) $types[$code]->priority] === [$frequency, $appliesTo, $group, $priority], "AC-12 jenis biaya {$code}");
    }
    $rates = DB::table('fee_rates')->get();
    foreach ($rates as $rate) {
        demoCheck($rate->amount % 1000 === 0 && $rate->amount >= 50000 && $rate->amount <= 10000000, "AC-12 nominal tarif {$rate->amount}");
    }
    $expectedRates = ['SPP' => 1600000, 'FORMULIR' => 300000, 'UANG_MASUK' => 10000000, 'DAFTAR_ULANG' => 1500000];
    foreach ([$sltp, $slta] as $unitId) {
        foreach ($expectedRates as $code => $amount) {
            $base = $rates->where('fee_type_id', $types[$code]->id)->where('unit_id', $unitId)->whereNull('cohort_id')->whereNull('dormitory_id');
            demoCheck($base->count() === 1 && $base->first()->amount === $amount && (int) $base->first()->effective_from_year_id === $yearP, "AC-12 tarif dasar {$code}");
        }
    }
    $cohortRates = $rates->whereNotNull('cohort_id');
    expect($cohortRates)->toHaveCount(1);
    demoCheck($cohortRates->first()->fee_type_id === $types['SPP']->id && $cohortRates->first()->unit_id === $slta && $cohortRates->first()->amount === 1500000, 'AC-12 tarif angkatan SLTA 1.500.000');
    demoCheck($rates->whereNotNull('dormitory_id')->isEmpty(), 'AC-12 tanpa tarif asrama');

    // AC-13
    $scholarships = DB::table('scholarships')->where('is_active', true)->get();
    expect($scholarships->pluck('mode')->sort()->values()->all())->toBe(['nominal', 'persen', 'potongan']);
    $overrides = DB::table('fee_overrides')->get();
    $owners = $overrides->whereNotNull('scholarship_id')->map(fn ($o) => $o->student_id !== null ? 's'.$o->student_id : 'a'.$o->applicant_id)->unique();
    demoCheck($owners->count() <= 3, "AC-13 pemilik beasiswa <= 3 (ada {$owners->count()})");
    expect($overrides->whereNull('scholarship_id'))->toHaveCount(1);
    demoCheck($overrides->whereNull('applicant_id')->every(fn ($o) => (int) $o->approved_by === demoUser('bendahara')->id), 'AC-13 keringanan santri disetujui bendahara');
    demoCheck($overrides->whereNotNull('applicant_id')->every(fn ($o) => in_array((int) $o->approved_by, [demoUser('admin-psb')->id, demoUser('bendahara')->id], true)), 'AC-13 keringanan jalur PSB disetujui aktor penerima');

    // AC-14
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
    foreach ($items->groupBy('invoice_id') as $invoiceItems) {
        demoCheck($invoiceItems->count() === 1 && $invoiceItems->first()->code === 'SPP', 'AC-14 satu item SPP per tagihan');
    }
    foreach ($items as $item) {
        $student = $students->firstWhere('id', $item->student_id);
        $expectedBase = ($student->unit_id === $slta && $cohortYear[$student->cohort_id] === 2024) ? 1500000 : 1600000;
        demoCheck($item->base_amount === $expectedBase, "AC-14 base_amount santri {$student->nis}");
    }
    $overrideOwner = fn (string $scholarship) => DB::table('fee_overrides')->join('scholarships', 'scholarships.id', '=', 'fee_overrides.scholarship_id')->where('scholarships.name', $scholarship)->whereNotNull('fee_overrides.student_id')->pluck('fee_overrides.student_id');
    $yatim = $overrideOwner('Subsidi Yatim');
    $tahfidz = $overrideOwner('Beasiswa Tahfidz');
    demoCheck($yatim->count() === 1 && $items->whereIn('student_id', $yatim)->isNotEmpty() && $items->whereIn('student_id', $yatim)->every(fn ($i) => $i->amount === 800000), 'AC-14 Subsidi Yatim bayar 800.000');
    demoCheck($tahfidz->count() === 1 && $items->whereIn('student_id', $tahfidz)->isNotEmpty() && $items->whereIn('student_id', $tahfidz)->every(fn ($i) => $i->amount === intdiv($i->base_amount, 2)), 'AC-14 Beasiswa Tahfidz 50%');
    $interview = DB::table('fee_overrides')->whereNull('scholarship_id')->pluck('student_id');
    demoCheck($interview->count() === 1 && $items->whereIn('student_id', $interview)->isNotEmpty() && $items->whereIn('student_id', $interview)->every(fn ($i) => $i->discount_amount === 300000), 'AC-14 keringanan wawancara 300.000');

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
    foreach ($monthly->where('status', 'sebagian') as $invoice) {
        demoCheck($invoice->paid_total % 1000 === 0 && $invoice->paid_total < $invoice->total, "AC-15 cicilan {$invoice->invoice_no}");
    }

    // AC-16
    $waves = AdmissionWave::query()->where('academic_year_id', $yearN)->orderBy('opens_on')->get();
    expect($waves)->toHaveCount(2);
    expect($waves->pluck('name')->all())->toBe(['Gelombang I', 'Gelombang II']);
    expect([$waves[0]->opens_on->toDateString(), $waves[0]->closes_on->toDateString(), $waves[0]->quota])->toBe(['2026-10-05', '2027-02-07', 40]);
    expect([$waves[1]->opens_on->toDateString(), $waves[1]->closes_on->toDateString(), $waves[1]->quota])->toBe(['2027-02-08', '2027-10-04', 20]);
    expect($waves->filter(fn ($w) => $w->isAcceptingRegistrationsOn($t1))->pluck('name')->all())->toBe(['Gelombang I']);
    $tracks = DB::table('admission_tracks')->where('is_active', true)->get();
    expect($tracks->pluck('code')->sort()->values()->all())->toBe(['REGULER', 'SUBSIDI']);
    expect((int) $tracks->firstWhere('code', 'SUBSIDI')->scholarship_id)->toBe((int) DB::table('scholarships')->where('name', 'Subsidi Uang Masuk')->value('id'));
    $requirements = DB::table('admission_requirements')->join('document_types', 'document_types.id', '=', 'admission_requirements.document_type_id')->pluck('admission_requirements.is_required', 'document_types.code');
    foreach (['KK', 'AKTA', 'RAPOR', 'FOTO'] as $code) {
        demoCheck((bool) ($requirements[$code] ?? false), "AC-16 syarat wajib {$code}");
    }
    demoCheck(isset($requirements['IJAZAH']) && ! $requirements['IJAZAH'], 'AC-16 IJAZAH tidak wajib');
    $applicants = DB::table('applicants')->get();
    expect($applicants)->toHaveCount(14);
    demoCheck($applicants->every(fn ($a) => $a->gender === 'L' && in_array($a->unit_id, [$sltp, $slta], true)), 'AC-16 pendaftar putra SLTP/SLTA');
    expect($applicants->groupBy('status')->map->count()->all())
        ->toEqualCanonicalizing(['cadangan' => 1, 'diajukan' => 4, 'diterima' => 5, 'diverifikasi' => 2, 'draft' => 1, 'ditolak' => 1]);
    foreach ($applicants->where('status', '!=', 'draft') as $applicant) {
        demoCheck(DB::table('invoices')->where('applicant_id', $applicant->id)->where('billing_group', 'PSB_DAFTAR')->where('total', 300000)->exists(), "AC-16 tagihan daftar {$applicant->name}");
    }
    foreach ($applicants->where('status', 'ditolak') as $applicant) {
        expect(DB::table('invoices')->where('applicant_id', $applicant->id)->where('status', 'belum')->count())->toBe(0);
    }
    $uangMasukId = $types['UANG_MASUK']->id;
    foreach (DB::table('invoices')->whereNotNull('applicant_id')->where('billing_group', 'PSB_TERIMA')->where('status', '!=', 'batal')->get() as $invoice) {
        $rows = DB::table('invoice_items')->where('invoice_id', $invoice->id)->get();
        demoCheck($rows->count() === 1 && $rows->first()->fee_type_id === $uangMasukId, "AC-16 tagihan penerimaan {$invoice->invoice_no} satu item UANG_MASUK");
    }
    $subsidiId = $tracks->firstWhere('code', 'SUBSIDI')->id;
    $subsidiAccepted = $applicants->where('admission_track_id', $subsidiId)->where('status', 'diterima');
    expect($subsidiAccepted)->toHaveCount(1);
    $converted = DB::table('students')->whereNotNull('applicant_id')->get();
    expect($converted)->toHaveCount(3);
    demoCheck($converted->contains('applicant_id', $subsidiAccepted->first()->id), 'AC-16 pendaftar SUBSIDI sudah daftar ulang');
    demoCheck(DB::table('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')->where('invoices.applicant_id', $subsidiAccepted->first()->id)->where('invoice_items.fee_type_id', $uangMasukId)->where('invoice_items.discount_amount', 5000000)->exists(), 'AC-16 diskon uang masuk SUBSIDI 5.000.000');
    foreach ($converted as $student) {
        expect(DB::table('invoices')->where('applicant_id', $student->applicant_id)->whereNull('student_id')->count())->toBe(0);
        expect(DB::table('fee_overrides')->where('applicant_id', $student->applicant_id)->whereNull('student_id')->count())->toBe(0);
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
    $participants = DB::table('achievement_student')->join('students', 'students.id', '=', 'achievement_student.student_id')->select('achievement_student.*', 'students.entered_on', 'students.gender')->get();
    $team = $achievements->where('kind', 'beregu')->filter(fn ($a) => $participants->where('achievement_id', $a->id)->count() >= 3);
    demoCheck($team->count() >= 2, 'AC-19 prestasi beregu >= 3 peserta');
    demoCheck($participants->contains(fn ($p) => strtolower((string) $p->role) === 'ketua tim'), 'AC-19 ketua tim');
    demoCheck($participants->every(fn ($p) => $p->gender === 'L'), 'AC-19 peserta putra');
    demoCheck($achievements->every(fn ($a) => stripos($a->title, 'Putri') === false), 'AC-19 tanpa judul Putri');
    $achievementPosts = DB::table('posts')->whereNotNull('achievement_id')->get();
    expect($achievementPosts)->toHaveCount(1);
    demoCheck($achievementPosts->first()->type === 'prestasi' && $achievementPosts->first()->status === 'terbit' && substr($achievementPosts->first()->published_at, 0, 10) <= $today, 'AC-19 berita prestasi');
    expect(DB::table('categories')->where('id', $achievementPosts->first()->category_id)->value('name'))->toBe('Prestasi Al-Khair');
    foreach ($participants as $participant) {
        $achievedOn = $achievements->firstWhere('id', $participant->achievement_id)->achieved_on;
        demoCheck(substr($participant->entered_on, 0, 10) <= substr($achievedOn, 0, 10), 'AC-19 peserta masuk sebelum prestasi');
    }

    // AC-20
    $briefCategories = ['Kegiatan Al-Khair', 'Prestasi Al-Khair', 'Info Kajian', 'Akidah & Manhaj', 'Tafsir & Hadits', 'Fiqh', 'Umum'];
    $postCategories = DB::table('categories')->where('type', 'post')->get();
    expect($postCategories->pluck('name')->sort()->values()->all())->toBe(collect($briefCategories)->sort()->values()->all());
    $posts = DB::table('posts')->get();
    demoCheck($postCategories->filter(fn ($c) => $posts->where('category_id', $c->id)->isNotEmpty())->count() >= 6, 'AC-20 >= 6 kategori terpakai');
    demoCheck($posts->where('status', 'terbit')->filter(fn ($p) => substr($p->published_at, 0, 10) <= $today)->count() >= 8, 'AC-20 berita terbit >= 8');
    demoCheck($posts->where('status', 'draft')->count() >= 1, 'AC-20 draft');
    $albums = DB::table('albums')->get();
    expect($albums)->toHaveCount(3);
    foreach ($albums as $album) {
        $paths = DB::table('photos')->where('album_id', $album->id)->pluck('path');
        $minimum = $album->title === "Fasilitas Ma'had" ? 6 : 4;
        demoCheck($paths->count() >= $minimum && $paths->every(fn ($p) => Storage::disk('public')->exists($p)), "AC-20 foto album {$album->slug}");
    }
    demoCheck($albums->contains('title', "Fasilitas Ma'had") && DB::table('photos')->where('album_id', $albums->firstWhere('title', "Fasilitas Ma'had")->id)->count() === 6, 'AC-20 album Fasilitas 6 foto');
    demoCheck($posts->whereNotNull('cover_path')->filter(fn ($p) => Storage::disk('public')->exists($p->cover_path))->count() >= 4, 'AC-20 sampul berita');
    // Revisi 3: video demo dikosongkan (ID rekaan tidak bisa diputar, ID nyata milik orang lain tidak boleh dipakai).
    expect(DB::table('videos')->count())->toBe(0);
    expect(array_column(blkStored(), 'type'))->not->toContain('video');
    // Placeholder gambar: PNG valid dan berukuran wajar (< 200 KB).
    foreach (DB::table('photos')->pluck('path')->merge($posts->whereNotNull('cover_path')->pluck('cover_path')) as $imagePath) {
        $bytes = Storage::disk('public')->get($imagePath);
        $info = getimagesizefromstring($bytes);
        demoCheck($info !== false && $info['mime'] === 'image/png' && strlen($bytes) < 200 * 1024, "AC-20 placeholder PNG valid {$imagePath}");
    }
    // Palet placeholder mengikuti merek (navy dan emas), tidak lagi dominan hijau.
    if (function_exists('imagecreatefromstring')) {
        $averages = [];
        foreach (DB::table('photos')->pluck('path')->merge($posts->whereNotNull('cover_path')->pluck('cover_path')) as $imagePath) {
            $image = imagecreatefromstring(Storage::disk('public')->get($imagePath));
            $sum = [0, 0, 0];
            $count = 0;
            foreach ([0.1, 0.3, 0.5, 0.7, 0.9] as $fy) {
                foreach ([0.1, 0.3, 0.5, 0.7, 0.9] as $fx) {
                    $rgb = imagecolorat($image, (int) (imagesx($image) * $fx), (int) (imagesy($image) * $fy));
                    $sum[0] += ($rgb >> 16) & 0xFF;
                    $sum[1] += ($rgb >> 8) & 0xFF;
                    $sum[2] += $rgb & 0xFF;
                    $count++;
                }
            }
            $averages[] = array_map(fn (int $v): float => $v / $count, $sum);
        }
        expect($averages)->not->toBeEmpty();
        foreach ($averages as [$r, $g, $b]) {
            demoCheck(! ($g > $r + 10 && $g > $b + 10), "placeholder tidak dominan hijau (rata-rata {$r},{$g},{$b})");
        }
        demoCheck(collect($averages)->contains(fn (array $c): bool => $c[2] > $c[0] && $c[2] >= $c[1]), 'ada placeholder bernuansa navy');
        demoCheck(collect($averages)->contains(fn (array $c): bool => $c[0] > $c[2] + 40 && $c[1] > $c[2] + 20), 'ada placeholder bernuansa emas');
    }
    $this->get(route('portal.home'))->assertOk()->assertSee("Ma'had Al-Khair Belitang");
    $this->get(route('portal.posts.index'))->assertOk();
    $this->get(route('portal.albums.index'))->assertOk();
    $this->get(route('portal.videos.index'))->assertOk()->assertSee(__('pondok.portal.videos.empty'));
    $this->get(route('portal.home'))->assertOk()->assertDontSee(__('pondok.portal.home.featured_videos'));
    $this->get(route('portal.pages.show', 'profil'))->assertOk();

    // AC-26
    $patterns = demoBrochureForbiddenPatterns();
    $numbers = demoBrochureNumbers();
    demoCheck(count($numbers) >= 3, 'AC-26 brosur memuat nomor telepon dan rekening');
    $brochureHits = demoScanColumns([
        'settings' => ['value'], 'pages' => ['body'], 'posts' => ['title', 'body'], 'staff' => ['name', 'phone'],
        'guardians' => ['name', 'phone'], 'applicants' => ['guardian_phone'], 'users' => ['name'],
    ], function (string $value) use ($patterns, $numbers): bool {
        $digits = preg_replace('/\D/', '', $value);
        foreach ($numbers as $number) {
            if (str_contains($digits, $number)) {
                return true;
            }
        }
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    });
    expect($brochureHits)->toBe([]);

    // AC-27
    $terms = DemoData::FORBIDDEN_CONTENT_TERMS;
    foreach (['maulid', 'isra mi', "isra' mi", 'haul', 'nisfu', 'tahun baru'] as $term) {
        demoCheck(in_array($term, $terms, true), "AC-27 daftar memuat {$term}");
    }
    $contentHits = demoScanColumns([
        'posts' => ['title', 'body'], 'pages' => ['body'], 'albums' => ['title', 'description'],
        'photos' => ['caption'], 'videos' => ['title', 'description'],
    ], function (string $value) use ($terms): bool {
        foreach ($terms as $term) {
            if (mb_stripos($value, $term) !== false) {
                return true;
            }
        }

        return false;
    });
    expect($contentHits)->toBe([]);

    // AC-59 (F036): profil lembaga fiktif dan logo merek, lewat seed pertama yang sama.
    $profileKeys = ['school.contacts', 'school.social_links', 'portal.home_blocks', 'school.logo_path', 'school.logo_desktop_path', 'school.logo_mobile_path', 'school.favicon_path'];
    $contacts = collect(Setting::get('school.contacts'));
    expect($contacts->count())->toBeGreaterThanOrEqual(2)
        ->and($contacts->pluck('label')->all())->toContain('Panitia PSB', 'Sekretariat', 'Kantor')
        ->and($contacts->every(fn (array $c): bool => preg_match('/^(08120000|0735000)[0-9]{4}$/', $c['phone']) === 1 && preg_match('/^[a-z0-9]{8}$/', $c['key']) === 1))->toBeTrue();
    $socials = collect(Setting::get('school.social_links'));
    expect($socials->count())->toBeGreaterThanOrEqual(2)
        ->and($socials->every(fn (array $l): bool => str_starts_with($l['url'], 'https://')))->toBeTrue();
    $homeBlocks = blkStored();
    $hero = collect($homeBlocks)->firstWhere('type', 'hero');
    expect(array_column($homeBlocks, 'type'))->toContain('hero', 'features', 'quote', 'cta', 'feed')->not->toContain('video')
        ->and(array_count_values(array_column($homeBlocks, 'type'))['hero'])->toBe(1)
        ->and(Album::query()->whereKey($hero['data']['album_id'] ?? 0)->exists())->toBeTrue();
    // Revisi 3: logo cetak (kop surat dan kuitansi) memakai logo simbol.
    $printLogo = Setting::get('school.logo_path');
    expect($printLogo)->toBeString()->not->toBeEmpty()
        ->and(Storage::disk('public')->exists($printLogo))->toBeTrue()
        ->and(Storage::disk('public')->get($printLogo))->toBe(file_get_contents(base_path('docs/brand/logo-mahad-simbol.png')));
    $logoPaths = [Setting::get('school.logo_desktop_path'), Setting::get('school.logo_mobile_path'), Setting::get('school.favicon_path')];
    expect(array_unique($logoPaths))->toHaveCount(3)
        ->and(collect($logoPaths)->every(fn (?string $path): bool => is_string($path) && str_starts_with($path, 'school/') && Storage::disk('public')->exists($path)))->toBeTrue()
        ->and(Storage::disk('public')->allFiles('school'))->toHaveCount(4)
        ->and(Storage::disk('public')->get($logoPaths[0]))->toBe(file_get_contents(base_path('docs/brand/logo-mahad-horizontal.png')))
        ->and(Storage::disk('public')->get($logoPaths[1]))->toBe(file_get_contents(base_path('docs/brand/logo-mahad-simbol.png')))
        ->and(Storage::disk('public')->get($logoPaths[2]))->toBe(file_get_contents(base_path('docs/brand/logo-mahad-simbol.png')));
    // Logo desktop: PNG horizontal (rasio >= 3:1), transparan, di bawah batas unggah logo.
    $desktopBytes = Storage::disk('public')->get($logoPaths[0]);
    $desktopInfo = getimagesizefromstring($desktopBytes);
    expect($desktopInfo)->not->toBeFalse()
        ->and($desktopInfo['mime'])->toBe('image/png')
        ->and($desktopInfo[0] / $desktopInfo[1])->toBeGreaterThanOrEqual(3.0)
        ->and(strlen($desktopBytes))->toBeLessThan(StorePublicImage::LOGO_MAX_KILOBYTES * 1024)
        ->and(substr($desktopBytes, 25, 1))->toBe("\x06"); // PNG IHDR color type 6 = RGBA (transparan)
    $profileJson = json_encode(Setting::query()->where(fn ($q) => $q->where('key', 'like', 'school.%')->orWhere('key', 'like', 'portal.%'))->pluck('value', 'key')->all());
    foreach (['085832333725', '085669941929'] as $brochureNumber) {
        expect($profileJson)->not->toContain($brochureNumber);
    }
    $profileSnapshot = collect($profileKeys)->mapWithKeys(fn (string $key): array => [$key => Setting::get($key)])->all();

    // AC-38 (F037): halaman dan menu contoh dari brief, lewat seed pertama yang sama.
    $pages = DB::table('pages')->get()->keyBy('slug');
    expect($pages->get('profil')->title)->toBe('Tentang Yayasan')
        ->and($pages->get('pengurus')->title)->toBe('Pengurus Al-Khair')
        ->and($pages->get('selayang-pandang')->title)->toBe('Selayang Pandang')
        ->and($pages->keys()->all())->toContain('visi-misi', 'kontak')
        ->and($pages->only(['profil', 'pengurus', 'selayang-pandang', 'visi-misi', 'kontak'])->pluck('status')->unique()->all())->toBe(['terbit']);
    // F043 AC-14: menu utama dengan satu grup halaman profil, footer, dan tombol header contoh fiktif tanpa kutipan.
    $primary = Setting::get('portal.navigation_primary');
    $groups = collect($primary)->where('type', 'group');
    $pageIds = $pages->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    expect($primary)->toBeArray()->and($groups)->toHaveCount(1)
        ->and(collect($primary)->pluck('type')->first())->toBe('home')
        ->and($groups->first()['label'])->not->toBeEmpty()
        ->and(count($groups->first()['children']))->toBeGreaterThanOrEqual(3)
        ->and(collect($groups->first()['children'])->every(fn (array $child): bool => $child['type'] === 'page' && in_array($child['page_id'], $pageIds, true)))->toBeTrue()
        ->and(Setting::get('portal.navigation_footer'))->toBeArray()->not->toBeEmpty()
        ->and(Setting::get('portal.footer_text')['body'])->toBeString()->not->toBeEmpty()
        ->and(Setting::get('portal.header_button'))->toBeArray()->and(Setting::get('portal.header_button')['type'])->not->toBeEmpty()
        ->and(Setting::query()->whereIn('key', ['portal.footer_quote_arabic', 'portal.footer_quote_translation', 'portal.footer_quote_source', 'portal.archived_footer_quote', 'portal.menu_items', 'portal.footer_links'])->count())->toBe(0);
    $demoHome = $this->get('/')->assertOk()->getContent();
    $demoNav = portalNavTexts($demoHome);
    expect($demoNav[0])->toBe('Beranda')->and($demoNav)->toContain('Tentang Yayasan', 'Pengurus Al-Khair', 'Selayang Pandang')
        ->and(substr_count(navHtml($demoHome, 'desktop'), '<details'))->toBe(1)
        ->and(profilFooter($demoHome))->not->toContain('<blockquote')->not->toContain('lang="ar"');
    $demoFooterText = mb_strtolower(json_encode([Setting::get('portal.footer_text'), Setting::get('portal.navigation_footer'), Setting::get('portal.header_button'), $groups->first()['label']], JSON_UNESCAPED_UNICODE));
    expect($demoFooterText)->not->toMatch('/\p{Arabic}/u')->not->toMatch('/maulid|haul|tahlilan|harlah|hadrah|shalawatan/');
    $forbidden = ['maulid', "isra' mi'raj", "nuzulul qur'an", 'tahun baru hijriah', "nisfu sya'ban", 'haul', 'tahlilan', 'harlah', 'hadrah', 'shalawatan'];
    $pagesText = mb_strtolower($pages->map(fn (object $row): string => $row->title.' '.$row->body)->implode(' '));
    expect(array_values(array_filter($forbidden, fn (string $word): bool => str_contains($pagesText, $word))))->toBe([]);
    $menuSnapshot = [Setting::get('portal.navigation_primary'), Setting::get('portal.navigation_footer'), Setting::get('portal.footer_text'), Setting::get('portal.header_button'), DB::table('pages')->count()];

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
    foreach ($profileSnapshot as $key => $value) {
        expect(Setting::get($key))->toBe($value, "AC-59 {$key} stabil pada seed ulang");
    }
    expect(Storage::disk('public')->allFiles('school'))->toHaveCount(4);
    expect(Setting::get('school.logo_path'))->toBe($printLogo);
    expect([Setting::get('portal.navigation_primary'), Setting::get('portal.navigation_footer'), Setting::get('portal.footer_text'), Setting::get('portal.header_button'), DB::table('pages')->count()])->toBe($menuSnapshot, 'AC-38 menu dan halaman stabil pada seed ulang');

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

    // AC-25 bagian 2: admin sudah mengganti nama jenjang SLTP sebelum seeding.
    $this->seed(InstallSeeder::class);
    Unit::query()->where('code', 'SLTP')->update(['name' => 'SMP IT']);

    $this->artisan('db:seed', ['--class' => DemoSeeder::class])
        ->expectsOutputToContain(__('pondok.demo.default_password_warning'))
        ->assertSuccessful();

    expect(AcademicYear::query()->where('is_active', true)->pluck('name')->all())->toBe(['2027/2028']);
    demoCheck(AcademicYear::query()->where('name', '2026/2027')->where('is_active', false)->exists(), 'AC-23 tahun lalu tidak aktif');
    foreach (['2027-04-01', '2027-07-01'] as $period) {
        demoCheck(DB::table('invoices')->where('billing_group', 'BULANAN')->whereDate('period', $period)->exists(), "AC-23 tagihan {$period}");
    }
    expect(DB::table('payments')->whereDate('paid_on', '>', '2027-07-03')->count())->toBe(0);
    $accepting = AdmissionWave::query()->get()->filter(fn ($w) => $w->isAcceptingRegistrationsOn($t2));
    expect($accepting)->toHaveCount(1);
    expect($accepting->first()->name)->toBe('Gelombang II');
    expect($accepting->first()->academic_year_id)->toBe(demoYearId('2027/2028'));
    expect($accepting->first()->closes_on->toDateString())->toBe('2027-10-04');
    expect(AcademicYear::query()->where('name', '2028/2029')->exists() ? AdmissionWave::query()->where('academic_year_id', demoYearId('2028/2029'))->count() : 0)->toBe(0);
    expect(DB::table('posts')->where('published_at', '>', '2027-07-03 23:59:59')->count())->toBe(0);
    expect(DB::table('achievements')->whereDate('achieved_on', '>', '2027-07-03')->count())->toBe(0);

    // AC-25 (bagian 2): nama unit yang sudah diubah admin tidak ditimpa.
    expect(Unit::query()->where('code', 'SLTP')->value('name'))->toBe('SMP IT');
    expect(Unit::query()->where('code', 'SLTA')->value('name'))->toBe('TSN/SMA');

    // Review #3 minor 5: NIS hasil daftar ulang tidak bentrok dengan NIS kurasi.
    $allNis = DB::table('students')->pluck('nis');
    expect($allNis->unique()->count())->toBe($allNis->count());
    $curatedNisT2 = collect(DemoData::students())->map(fn (array $row): string => (2027 + $row['entry_offset']).str_pad((string) $row['seq'], 4, '0', STR_PAD_LEFT));
    $convertedNis = DB::table('students')->whereNotNull('applicant_id')->pluck('nis');
    expect($convertedNis)->toHaveCount(3);
    expect($convertedNis->intersect($curatedNisT2)->all())->toBe([]);

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

    // Tagihan bulanan hanya berisi SPP, dan santri hasil daftar ulang (belum serah terima) tidak ditagih.
    $codes = DB::table('invoice_items')->join('fee_types', 'fee_types.id', '=', 'invoice_items.fee_type_id')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
        ->where('invoices.billing_group', 'BULANAN')->pluck('fee_types.code')->unique()->all();
    expect($codes)->toBe(['SPP']);
    $fromPsb = DB::table('students')->whereNotNull('applicant_id')->pluck('id');
    demoCheck($fromPsb->count() === 3, 'VERIFY tiga santri hasil daftar ulang');
    expect(DB::table('invoices')->whereIn('student_id', $fromPsb)->where('billing_group', 'BULANAN')->count())->toBe(0);
    expect(DB::table('room_assignments')->whereIn('student_id', $fromPsb)->count())->toBe(0);

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

    // Khusus putra dan tanpa SD, ditegakkan lintas tabel (bukan hanya data yang dikurasi).
    $sdId = demoUnitId('SD');
    expect(DB::table('applicants')->where('gender', '!=', 'L')->count())->toBe(0);
    expect(DB::table('students')->where('gender', '!=', 'L')->count())->toBe(0);
    expect(DB::table('dormitories')->where('gender', '!=', 'L')->count())->toBe(0);
    expect(DB::table('classrooms')->where('gender', '!=', 'L')->count())->toBe(0);
    expect(DB::table('staff')->whereIn('position', ['ustadz', 'musyrif', 'Mudir'])->where('gender', '!=', 'L')->count())->toBe(0);
    expect(DB::table('dormitory_supervisors')->join('staff', 'staff.id', '=', 'dormitory_supervisors.staff_id')->where('staff.gender', '!=', 'L')->count())->toBe(0);
    expect(DB::table('enrollments')->join('classrooms', 'classrooms.id', '=', 'enrollments.classroom_id')->where('classrooms.unit_id', $sdId)->count())->toBe(0);
    expect(DB::table('posts')->where('unit_id', $sdId)->count())->toBe(0);
    expect(DB::table('unit_user')->where('unit_id', $sdId)->count())->toBe(0);
    expect(DB::table('invoices')->join('students', 'students.id', '=', 'invoices.student_id')->where('students.unit_id', $sdId)->count())->toBe(0);
    expect(DB::table('fee_overrides')->join('students', 'students.id', '=', 'fee_overrides.student_id')->where('students.unit_id', $sdId)->count())->toBe(0);

    // Gelombang bersambung: tanpa celah dan tanpa tumpang tindih, tepat satu terbuka pada T1.
    $allWaves = AdmissionWave::query()->orderBy('opens_on')->get();
    foreach ($allWaves->slice(1)->values() as $index => $wave) {
        $previous = $allWaves[$index];
        demoCheck($previous->closes_on->addDay()->toDateString() === $wave->opens_on->toDateString(), "VERIFY gelombang {$previous->name} bersambung ke {$wave->name}");
    }
    expect($allWaves->filter(fn ($w) => $w->isAcceptingRegistrationsOn(CarbonImmutable::now('Asia/Jakarta')))->count())->toBe(1);

    // Tidak ada data brosur di seluruh kolom semua tabel (termasuk nomor dokumen terenkripsi, snapshot surat, keterangan foto).
    $numbers = demoBrochureNumbers();
    $patterns = demoBrochureForbiddenPatterns();
    $matches = static function (string $value) use ($numbers, $patterns): bool {
        $digits = preg_replace('/\D/', '', $value);
        foreach ($numbers as $number) {
            if (str_contains($digits, $number)) {
                return true;
            }
        }
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    };
    $skipTables = ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];
    $textTypes = ['varchar', 'text', 'longtext', 'mediumtext', 'json', 'string', 'char'];
    $scan = [];
    foreach (Schema::getTables() as $table) {
        if (in_array($table['name'], $skipTables, true)) {
            continue;
        }
        foreach (Schema::getColumns($table['name']) as $column) {
            if (in_array(strtolower((string) $column['type_name']), $textTypes, true) && ! in_array($column['name'], ['password', 'remember_token', 'access_token_hash'], true)) {
                $scan[$table['name']][] = $column['name'];
            }
        }
    }
    demoCheck(isset($scan['posts'], $scan['issued_letters'], $scan['photos'], $scan['activity_log']), 'VERIFY pemindaian mencakup tabel teks utama');
    expect(demoScanColumns($scan, $matches))->toBe([]);
    foreach (Document::query()->whereNotNull('number')->pluck('number') as $number) {
        demoCheck(! $matches((string) $number), 'VERIFY nomor dokumen terdekripsi bebas data brosur');
    }

    // Manhaj: istilah terlarang tidak ada di prestasi, surat, dan seluruh konten publik.
    $terms = DemoData::FORBIDDEN_CONTENT_TERMS;
    $termMatcher = function (string $value) use ($terms): bool {
        foreach ($terms as $term) {
            if (mb_stripos($value, $term) !== false) {
                return true;
            }
        }

        return false;
    };
    expect(demoScanColumns([
        'achievements' => ['title', 'event_name', 'organizer'], 'issued_letters' => ['purpose'],
        'posts' => ['title', 'excerpt', 'body'], 'pages' => ['body'], 'albums' => ['title', 'description'],
        'photos' => ['caption'], 'videos' => ['title', 'description'], 'categories' => ['name'],
    ], $termMatcher))->toBe([]);

    // Portal menampilkan data revisi.
    $this->get('/')->assertOk()->assertSee("Generasi Beradab Bersama Al-Qur'an");
    $this->get('/berita')->assertOk()->assertSee('Penerimaan Santri Baru');
    $this->get('/profil')->assertOk()->assertSee('Selayang Pandang');
    $kontakHtml = $this->get('/kontak')->assertOk()->assertSee('Mojosari')->getContent();
    preg_match('#<main\b.*?</main>#is', $kontakHtml, $kontakMain);
    $kontak = profilVisibleText($kontakMain[0] ?? '');
    foreach (Setting::get('school.contacts') as $contact) {
        $shown = app(FormatPhoneForDisplay::class)->handle($contact['phone']);
        expect(substr_count($kontak, $shown))->toBe(1, "AC-8 nomor {$shown} tepat sekali di /kontak");
    }
    expect(substr_count($kontak, 'info@alkhair-belitang.test'))->toBe(1);

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

    // Cakupan jenjang kesantrian (hanya TSN/SMA): tidak melihat SLTP, lebih sedikit dari total.
    $this->actingAs(demoUser('kesantrian'));
    expect(Student::query()->where('unit_id', demoUnitId('SLTP'))->count())->toBe(0);
    expect(Student::query()->count())->toBe(Student::withoutUnitScope()->where('unit_id', demoUnitId('SLTA'))->count());
    expect(DB::table('invoices')->count())->toBeGreaterThan(Invoice::query()->count());
})->group('seeder-demo');
