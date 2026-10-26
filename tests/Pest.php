<?php

use App\Actions\Absensi\BuildAttendanceSheet;
use App\Actions\Absensi\RecordClassroomAttendance;
use App\Actions\Dokumen\IssueLetter;
use App\Actions\Dokumen\LetterNumbering;
use App\Actions\Kedisiplinan\RecordStudentViolation;
use App\Actions\Kesehatan\RecordHealthVisit;
use App\Actions\Keuangan\CancelPayment;
use App\Actions\Keuangan\FinanceReportFilter;
use App\Actions\Keuangan\GenerateAnnualInvoices;
use App\Actions\Keuangan\GenerateInvoiceNumber;
use App\Actions\Keuangan\GenerateMonthlyInvoices;
use App\Actions\Keuangan\RecordPayment;
use App\Actions\Keuangan\RecordWalletTransaction;
use App\Actions\Keuangan\WalletBalance;
use App\Actions\Konten\GetPortalNavigation;
use App\Actions\Konten\GetSchoolProfile;
use App\Actions\Konten\UpdatePortalFooter;
use App\Actions\Konten\UpdatePortalHome;
use App\Actions\Perizinan\SubmitLeaveRequest;
use App\Actions\Prestasi\SaveAchievement;
use App\Actions\Psb\GenerateApplicantNumber;
use App\Actions\Psb\GenerateRegistrationInvoices;
use App\Actions\Psb\ReRegisterApplicant;
use App\Actions\Psb\SubmitApplication;
use App\Actions\Tahfidz\RecordMemorization;
use App\Actions\Tahfidz\RecordTahfidzExam;
use App\Actions\Tahfidz\RefreshJuzProgress;
use App\Enums\Module;
use App\Filament\Resources\Applicants\Pages\ViewApplicant;
use App\Livewire\PortalWali\ResetPassword as PortalResetPassword;
use App\Models\AcademicYear;
use App\Models\Achievement;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionTrack;
use App\Models\AdmissionWave;
use App\Models\Album;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Category;
use App\Models\Classroom;
use App\Models\Cohort;
use App\Models\DisciplineLevel;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Dormitory;
use App\Models\DormitorySupervisor;
use App\Models\Enrollment;
use App\Models\FeeOverride;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\Halaqah;
use App\Models\HalaqahMember;
use App\Models\HealthVisit;
use App\Models\HomecomingPeriod;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\IssuedLetter;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\MemorizationRecord;
use App\Models\Payment;
use App\Models\Photo;
use App\Models\Post;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Scholarship;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\StaticPage;
use App\Models\Student;
use App\Models\StudentViolation;
use App\Models\TahfidzExam;
use App\Models\Unit;
use App\Models\User;
use App\Models\Video;
use App\Models\ViolationType;
use App\Models\Visit;
use App\Models\WalletTransaction;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\AdmissionTrackSeeder;
use Database\Seeders\ContentCategorySeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\InstallSeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\StaticPageSeeder;
use Database\Seeders\UnitSeeder;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Helper modul Asrama (F003)
|--------------------------------------------------------------------------
|
| Berawalan `asrama` agar tidak bentrok dengan helper global di file test lain.
|
*/

function asramaActiveYear(): AcademicYear
{
    return AcademicYear::factory()->active()->create();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function asramaDormitory(string $gender = 'L', array $attributes = []): Dormitory
{
    return Dormitory::factory()->create(['gender' => $gender, ...$attributes]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function asramaRoom(int $capacity = 10, string $gender = 'L', array $attributes = [], ?Dormitory $dormitory = null): Room
{
    $dormitory ??= asramaDormitory($gender);

    return Room::factory()->create(['dormitory_id' => $dormitory->id, 'capacity' => $capacity, ...$attributes]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function asramaStudent(string $gender = 'L', array $attributes = []): Student
{
    return Student::factory()->create(['gender' => $gender, ...$attributes]);
}

/**
 * Penempatan aktif langsung lewat factory (tanpa Action).
 *
 * @param  array<string, mixed>  $attributes
 */
function asramaResident(Student $student, Room $room, array $attributes = []): RoomAssignment
{
    return RoomAssignment::factory()->create([
        'student_id' => $student->id,
        'room_id' => $room->id,
        'academic_year_id' => AcademicYear::query()->where('is_active', true)->value('id') ?? AcademicYear::factory()->active()->create()->id,
        'start_on' => '2026-07-15',
        ...$attributes,
    ]);
}

/**
 * User dengan peran, opsional dibatasi ke jenjang (kode unit, mis. 'SLTP').
 * Seeder peran dan unit harus sudah dijalankan oleh test pemanggil.
 *
 * @param  list<string>  $unitCodes
 */
function asramaUser(string $role, array $unitCodes = []): User
{
    $user = User::factory()->create()->assignRole($role);

    foreach ($unitCodes as $code) {
        $user->units()->attach(Unit::query()->where('code', $code)->value('id'));
    }

    return $user;
}

/**
 * Pola regex untuk pesan `pondok.asrama.errors.<reason>`; placeholder `:nama` bebas isi.
 */
function asramaMessagePattern(string $reason): string
{
    $message = __("pondok.asrama.errors.{$reason}");

    return '/^'.preg_replace('/\\\\:[A-Za-z_]+/', '.+', preg_quote($message, '/')).'$/su';
}

/**
 * Pastikan callback melempar ValidationException dengan pesan kunci lang `reason`.
 * Bila `$field` diisi, error harus ada pada field tersebut.
 */
function asramaAssertRejected(Closure $callback, string $reason, ?string $field = null): void
{
    expect(__("pondok.asrama.errors.{$reason}"))->not->toBe("pondok.asrama.errors.{$reason}");

    try {
        $callback();
    } catch (ValidationException $exception) {
        $errors = $exception->errors();

        if ($field !== null) {
            expect($errors)->toHaveKey($field);
        }

        $matches = collect($errors)->flatten()->contains(fn (string $message): bool => preg_match(asramaMessagePattern($reason), $message) === 1);

        expect($matches)->toBeTrue("Pesan error tidak sesuai pondok.asrama.errors.{$reason}: ".json_encode($errors));

        return;
    }

    test()->fail('ValidationException diharapkan, tetapi tidak dilempar.');
}

/**
 * Notifikasi Filament yang terkirim pada request Livewire terakhir.
 *
 * @return Collection<int, array<string, mixed>>
 */
function asramaNotifications(): Collection
{
    app(NotificationMailbox::class)->flush();

    $component = new Notifications;
    $component->mount();
    $component->pullNotificationsFromSession();

    return $component->notifications->map(fn (Notification $notification): array => $notification->toArray())->values();
}

/*
|--------------------------------------------------------------------------
| Helper modul Keuangan (F004)
|--------------------------------------------------------------------------
|
| Berawalan `keuangan` agar tidak bentrok dengan helper global di file test lain.
|
*/

/**
 * User dengan peran, opsional dibatasi ke jenjang (kode unit, mis. 'SLTP').
 * Seeder peran dan unit harus sudah dijalankan oleh test pemanggil.
 *
 * @param  list<string>  $unitCodes
 */
function keuanganUser(string $role, array $unitCodes = []): User
{
    $user = User::factory()->create()->assignRole($role);

    foreach ($unitCodes as $code) {
        $user->units()->attach(Unit::query()->where('code', $code)->value('id'));
    }

    return $user;
}

/**
 * Pola regex untuk pesan `pondok.keuangan.errors.<reason>`; placeholder `:nama` bebas isi.
 * Pesan dipecah di placeholder lalu tiap potongan di-quote sendiri (hindari kesalahan escape ':').
 */
function keuanganMessagePattern(string $reason): string
{
    $message = __("pondok.keuangan.errors.{$reason}");
    $parts = preg_split('/:[A-Za-z_]+/', $message);

    return '/^'.implode('.+', array_map(fn (string $part): string => preg_quote($part, '/'), $parts)).'$/su';
}

/**
 * Pastikan callback melempar ValidationException dengan pesan kunci lang `reason`.
 * Bila `$field` diisi, error harus ada pada field tersebut.
 */
function keuanganAssertRejected(Closure $callback, string $reason, ?string $field = null): void
{
    expect(__("pondok.keuangan.errors.{$reason}"))->not->toBe("pondok.keuangan.errors.{$reason}");

    try {
        $callback();
    } catch (ValidationException $exception) {
        $errors = $exception->errors();

        if ($field !== null) {
            expect($errors)->toHaveKey($field);
        }

        $matches = collect($errors)->flatten()->contains(fn (string $message): bool => preg_match(keuanganMessagePattern($reason), $message) === 1);

        expect($matches)->toBeTrue("Pesan error tidak sesuai pondok.keuangan.errors.{$reason}: ".json_encode($errors));

        return;
    }

    test()->fail('ValidationException diharapkan, tetapi tidak dilempar.');
}

/**
 * Fixture resolver bersama: jenjang SD dan SLTP, empat tahun ajaran, tiga angkatan SLTP, dua asrama,
 * dan jenis biaya ASRAMA (bulanan, mukim). Tahun ajaran aktif: 2026/2027.
 * Dengan `$reverseYears` id tahun ajaran terbalik terhadap urutan `starts_on`.
 *
 * @return object{sd: Unit, sltp: Unit, y2024: AcademicYear, y2025: AcademicYear, y2026: AcademicYear, y2027: AcademicYear, cohort2024: Cohort, cohort2025: Cohort, cohort2026: Cohort, d1: Dormitory, d2: Dormitory, type: FeeType}
 */
function keuanganFixture(bool $reverseYears = false): object
{
    test()->seed(UnitSeeder::class);

    $specs = [
        'y2024' => ['2024/2025', '2024-07-15', '2025-06-30', false],
        'y2025' => ['2025/2026', '2025-07-15', '2026-06-30', false],
        'y2026' => ['2026/2027', '2026-07-15', '2027-06-30', true],
        'y2027' => ['2027/2028', '2027-07-15', '2028-06-30', false],
    ];

    if ($reverseYears) {
        $specs = array_reverse($specs, true);
    }

    $years = [];
    foreach ($specs as $key => [$name, $start, $end, $active]) {
        $years[$key] = AcademicYear::factory()->create(['name' => $name, 'starts_on' => $start, 'ends_on' => $end, 'is_active' => $active]);
    }

    $sltp = Unit::query()->where('code', 'SLTP')->firstOrFail();

    return (object) [
        'sd' => Unit::query()->where('code', 'SD')->firstOrFail(),
        'sltp' => $sltp,
        'y2024' => $years['y2024'],
        'y2025' => $years['y2025'],
        'y2026' => $years['y2026'],
        'y2027' => $years['y2027'],
        'cohort2024' => Cohort::factory()->create(['unit_id' => $sltp->id, 'entry_year' => 2024, 'name' => 'Angkatan 2024']),
        'cohort2025' => Cohort::factory()->create(['unit_id' => $sltp->id, 'entry_year' => 2025, 'name' => 'Angkatan 2025']),
        'cohort2026' => Cohort::factory()->create(['unit_id' => $sltp->id, 'entry_year' => 2026, 'name' => 'Angkatan 2026']),
        'd1' => Dormitory::factory()->create(['code' => 'D1', 'name' => 'Asrama D1', 'gender' => 'L']),
        'd2' => Dormitory::factory()->create(['code' => 'D2', 'name' => 'Asrama D2', 'gender' => 'L']),
        'type' => FeeType::factory()->resident()->create(['code' => 'ASRAMA', 'name' => 'Asrama']),
    ];
}

/**
 * Tarif langsung lewat factory (tanpa Action).
 */
function keuanganRate(FeeType $type, AcademicYear $year, Unit $unit, int $amount, ?Cohort $cohort = null, ?Dormitory $dormitory = null, ?int $waveId = null): FeeRate
{
    return FeeRate::factory()->create([
        'fee_type_id' => $type->id,
        'effective_from_year_id' => $year->id,
        'unit_id' => $unit->id,
        'cohort_id' => $cohort?->id,
        'dormitory_id' => $dormitory?->id,
        'admission_wave_id' => $waveId,
        'amount' => $amount,
    ]);
}

/**
 * Santri SLTP dari angkatan tertentu, opsional mukim di asrama tertentu (penempatan aktif lewat factory).
 */
function keuanganStudent(Cohort $cohort, ?Dormitory $dormitory = null): Student
{
    $student = Student::factory()->forCohort($cohort)->create(['gender' => 'L']);

    if ($dormitory !== null) {
        asramaResident($student, asramaRoom(10, 'L', [], $dormitory));
    }

    return $student;
}

/**
 * Data masukan SaveFeeType yang valid.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function keuanganFeeTypeData(array $overrides = []): array
{
    return [
        'code' => 'SPP',
        'name' => 'SPP',
        'frequency' => 'bulanan',
        'applies_to' => 'semua',
        'billing_group' => 'BULANAN',
        'priority' => 100,
        'is_active' => true,
        ...$overrides,
    ];
}

/**
 * Data masukan SaveFeeRate yang valid (tarif jenjang default).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function keuanganFeeRateData(FeeType $type, AcademicYear $year, Unit $unit, array $overrides = []): array
{
    return [
        'fee_type_id' => $type->id,
        'effective_from_year_id' => $year->id,
        'unit_id' => $unit->id,
        'cohort_id' => null,
        'dormitory_id' => null,
        'admission_wave_id' => null,
        'amount' => 400000,
        ...$overrides,
    ];
}

/*
|--------------------------------------------------------------------------
| Helper beasiswa & keringanan (F005)
|--------------------------------------------------------------------------
|
| Berawalan `keringanan`. Memakai `keuanganUser` dan `keuanganAssertRejected`.
| Seeder peran harus sudah dijalankan oleh test pemanggil bila butuh user.
|
*/

/**
 * Fixture: jenjang SD dan SLTP, jenis biaya SPP, santri aktif SLTP `a` dan `b`, santri aktif SD `c`.
 *
 * @return object{sd: Unit, sltp: Unit, spp: FeeType, a: Student, b: Student, c: Student}
 */
function keringananFixture(): object
{
    test()->seed(UnitSeeder::class);

    $sd = Unit::query()->where('code', 'SD')->firstOrFail();
    $sltp = Unit::query()->where('code', 'SLTP')->firstOrFail();
    $sltpCohort = Cohort::factory()->create(['unit_id' => $sltp->id, 'entry_year' => 2025]);
    $sdCohort = Cohort::factory()->create(['unit_id' => $sd->id, 'entry_year' => 2025]);

    return (object) [
        'sd' => $sd,
        'sltp' => $sltp,
        'spp' => FeeType::factory()->monthly()->create(['code' => 'SPP', 'name' => 'SPP']),
        'a' => Student::factory()->forCohort($sltpCohort)->create(['nis' => '1000000001', 'name' => 'Ahmad']),
        'b' => Student::factory()->forCohort($sltpCohort)->create(['nis' => '1000000002', 'name' => 'Budi']),
        'c' => Student::factory()->forCohort($sdCohort)->create(['nis' => '1000000003', 'name' => 'Candra']),
    ];
}

/**
 * Keringanan langsung lewat factory (tanpa Action).
 *
 * @param  array<string, mixed>  $attributes
 */
function keringananOverride(array $attributes = []): FeeOverride
{
    return FeeOverride::factory()->create($attributes);
}

/**
 * Data masukan SaveFeeOverride yang valid (santri `a`, SPP, nominal, 2026-07-01 tanpa akhir).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function keringananOverrideData(object $f, array $overrides = []): array
{
    return [
        'student_id' => $f->a->id,
        'applicant_id' => null,
        'fee_type_id' => $f->spp->id,
        'scholarship_id' => null,
        'mode' => 'nominal',
        'value' => 250000,
        'reason' => 'Hafal 10 juz',
        'valid_from' => '2026-07-01',
        'valid_until' => null,
        ...$overrides,
    ];
}

/**
 * Data masukan SaveScholarship yang valid.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function keringananScholarshipData(array $overrides = []): array
{
    return [
        'name' => 'Beasiswa Tahfidz',
        'mode' => 'persen',
        'default_value' => 50,
        'sponsor' => null,
        'is_active' => true,
        ...$overrides,
    ];
}

/*
|--------------------------------------------------------------------------
| Helper generator tagihan (F006)
|--------------------------------------------------------------------------
|
| Berawalan `tagihan`. Memakai `keuanganRate`, `keuanganUser`, `asramaResident`,
| `asramaRoom`, dan `keringananOverride`. Seeder peran harus sudah dijalankan
| oleh test pemanggil bila butuh user.
|
*/

/**
 * Fixture umum: tahun ajaran aktif 2026/2027 (2026-07-01 s.d. 2027-06-30), jenjang SLTP dan SLTA,
 * angkatan 2026 masing-masing, asrama A dan B, jenis biaya bulanan SPP (semua, prioritas 3),
 * ASRAMA (mukim, prioritas 2), MAKAN (mukim, prioritas 1), semuanya grup BULANAN.
 * Tarif jenjang SLTP (bukan SLTA): SPP 500.000, ASRAMA 300.000, MAKAN 400.000.
 *
 * @return object{year: AcademicYear, sltp: Unit, slta: Unit, cohort: Cohort, cohortSlta: Cohort, dormA: Dormitory, dormB: Dormitory, spp: FeeType, asrama: FeeType, makan: FeeType, period: CarbonImmutable}
 */
function tagihanFixture(bool $withRates = true): object
{
    test()->seed(UnitSeeder::class);

    $year = AcademicYear::factory()->active()->create(['name' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
    $sltp = Unit::query()->where('code', 'SLTP')->firstOrFail();
    $slta = Unit::query()->where('code', 'SLTA')->firstOrFail();

    $f = (object) [
        'year' => $year,
        'sltp' => $sltp,
        'slta' => $slta,
        'cohort' => Cohort::factory()->create(['unit_id' => $sltp->id, 'entry_year' => 2026, 'name' => 'Angkatan 2026']),
        'cohortSlta' => Cohort::factory()->create(['unit_id' => $slta->id, 'entry_year' => 2026, 'name' => 'Angkatan 2026']),
        'dormA' => Dormitory::factory()->create(['code' => 'A', 'name' => 'Asrama A', 'gender' => 'L']),
        'dormB' => Dormitory::factory()->create(['code' => 'B', 'name' => 'Asrama B', 'gender' => 'L']),
        'spp' => FeeType::factory()->monthly()->create(['code' => 'SPP', 'name' => 'SPP', 'billing_group' => 'BULANAN', 'priority' => 3]),
        'asrama' => FeeType::factory()->monthly()->resident()->create(['code' => 'ASRAMA', 'name' => 'Asrama', 'billing_group' => 'BULANAN', 'priority' => 2]),
        'makan' => FeeType::factory()->monthly()->resident()->create(['code' => 'MAKAN', 'name' => 'Makan', 'billing_group' => 'BULANAN', 'priority' => 1]),
        'period' => CarbonImmutable::parse('2026-10-01', 'Asia/Jakarta'),
    ];

    if ($withRates) {
        keuanganRate($f->spp, $year, $sltp, 500000);
        keuanganRate($f->asrama, $year, $sltp, 300000);
        keuanganRate($f->makan, $year, $sltp, 400000);
    }

    return $f;
}

/**
 * Santri aktif (masuk 2026-07-15) di angkatan fixture, opsional mukim (penempatan aktif lewat factory).
 *
 * @param  array<string, mixed>  $attributes
 */
function tagihanStudent(object $f, bool $resident = false, ?Dormitory $dormitory = null, array $attributes = [], ?Cohort $cohort = null): Student
{
    $student = Student::factory()->forCohort($cohort ?? $f->cohort)->create(['gender' => 'L', 'entered_on' => '2026-07-15', ...$attributes]);

    if ($resident) {
        asramaResident($student, asramaRoom(10, 'L', [], $dormitory ?? $f->dormA));
    }

    return $student;
}

/**
 * Jalankan generator dari container (tanpa cakupan jenjang tambahan).
 *
 * @return array{period: string, created_invoices: int, added_items: int, existing_invoices: int, skipped: list<array{student_id: int, fee_type_id: int, reason: string}>}
 */
function tagihanGenerate(string $period = '2026-10-01', ?User $by = null): array
{
    return app(GenerateMonthlyInvoices::class)->handle(CarbonImmutable::parse($period, 'Asia/Jakarta'), $by);
}

/**
 * Tagihan aktif (bukan batal) santri untuk periode dan grup, tanpa cakupan jenjang.
 */
function tagihanInvoiceFor(Student $student, string $group = 'BULANAN', string $period = '2026-10-01'): ?Invoice
{
    return Invoice::withoutUnitScope()
        ->where('student_id', $student->id)
        ->where('billing_group', $group)
        ->whereDate('period', $period)
        ->where('status', '<>', 'batal')
        ->first();
}

/**
 * Item tagihan, diindeks per `fee_type_id`.
 *
 * @return Collection<int, InvoiceItem>
 */
function tagihanItems(Invoice $invoice): Collection
{
    return InvoiceItem::query()->where('invoice_id', $invoice->id)->get()->keyBy('fee_type_id');
}

/**
 * Tagihan manual lewat factory (periode selalu `startOfMonth()` lewat model).
 *
 * @param  array<string, mixed>  $attributes
 */
function tagihanInvoice(Student $student, AcademicYear $year, array $attributes = []): Invoice
{
    return Invoice::factory()->create([
        'student_id' => $student->id,
        'academic_year_id' => $year->id,
        'period' => CarbonImmutable::parse('2026-10-01', 'Asia/Jakarta')->startOfMonth(),
        'billing_group' => 'BULANAN',
        ...$attributes,
    ]);
}

/**
 * Pola regex untuk pesan `pondok.invoice.errors.<reason>`; placeholder `:nama` bebas isi.
 */
function tagihanMessagePattern(string $reason): string
{
    $parts = preg_split('/:[A-Za-z_]+/', __("pondok.invoice.errors.{$reason}"));

    return '/^'.implode('.+', array_map(fn (string $part): string => preg_quote($part, '/'), $parts)).'$/su';
}

/**
 * Pastikan callback melempar ValidationException dengan pesan kunci lang `pondok.invoice.errors.<reason>`.
 * Bila `$field` diisi, error harus ada pada field tersebut.
 */
function tagihanAssertRejected(Closure $callback, string $reason, ?string $field = null): void
{
    expect(__("pondok.invoice.errors.{$reason}"))->not->toBe("pondok.invoice.errors.{$reason}");

    try {
        $callback();
    } catch (ValidationException $exception) {
        $errors = $exception->errors();

        if ($field !== null) {
            expect($errors)->toHaveKey($field);
        }

        $matches = collect($errors)->flatten()->contains(fn (string $message): bool => preg_match(tagihanMessagePattern($reason), $message) === 1);

        expect($matches)->toBeTrue("Pesan error tidak sesuai pondok.invoice.errors.{$reason}: ".json_encode($errors));

        return;
    }

    test()->fail('ValidationException diharapkan, tetapi tidak dilempar.');
}

/**
 * Teks polos halaman Livewire: tag dibuang, entitas didekode, spasi tak-putus dirapikan.
 */
function tagihanPlainText(Testable $component): string
{
    $text = html_entity_decode(strip_tags($component->html()), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $text));
}

/*
|--------------------------------------------------------------------------
| Helper pembayaran & kuitansi (F007)
|--------------------------------------------------------------------------
|
| Berawalan `bayar`. Memakai `tagihanFixture`, `tagihanStudent`, `tagihanGenerate`.
| Waktu uji bawaan: 2026-10-15 10:00 Asia/Jakarta (diatur oleh test pemanggil).
|
*/

/**
 * Tagihan Oktober 2026 dari generator untuk santri mukim SLTP (MAKAN 400.000, ASRAMA 300.000,
 * SPP 500.000; total 1.200.000).
 */
function bayarInvoice(object $f, ?Student $student = null): Invoice
{
    $student ??= tagihanStudent($f, resident: true);
    tagihanGenerate();

    return tagihanInvoiceFor($student);
}

/**
 * Catat pembayaran lewat Action dari container (tunai, hari ini, kecuali ditimpa).
 *
 * @param  array<string, mixed>  $overrides
 */
function bayarRecord(Invoice $invoice, int $amount, array $overrides = [], ?User $by = null): Payment
{
    return app(RecordPayment::class)->handle($invoice, $by ?? User::factory()->create(), [
        'amount' => $amount,
        'paid_on' => now('Asia/Jakarta')->toDateString(),
        'method' => 'tunai',
        ...$overrides,
    ]);
}

/**
 * `paid_amount` per item tagihan, diindeks kode jenis biaya (MAKAN, ASRAMA, SPP).
 *
 * @return array<string, int>
 */
function bayarPaidByCode(Invoice $invoice): array
{
    return InvoiceItem::query()->where('invoice_id', $invoice->id)->with('feeType')->get()
        ->mapWithKeys(fn (InvoiceItem $item): array => [$item->feeType->code => (int) $item->paid_amount])
        ->all();
}

/**
 * Alokasi sebuah pembayaran per kode jenis biaya (dari baris payment_allocations).
 *
 * @return array<string, int>
 */
function bayarAllocationsByCode(Payment $payment): array
{
    return $payment->allocations()->with('invoiceItem.feeType')->get()
        ->mapWithKeys(fn ($allocation): array => [$allocation->invoiceItem->feeType->code => (int) $allocation->amount])
        ->all();
}

/**
 * Pola regex untuk pesan `pondok.payment.errors.<reason>`; placeholder `:nama` bebas isi.
 */
function bayarMessagePattern(string $reason): string
{
    $parts = preg_split('/:[A-Za-z_]+/', __("pondok.payment.errors.{$reason}"));

    return '/^'.implode('.+', array_map(fn (string $part): string => preg_quote($part, '/'), $parts)).'$/su';
}

/**
 * Pastikan callback melempar ValidationException dengan pesan kunci lang `pondok.payment.errors.<reason>`.
 * Bila `$field` diisi, error harus ada pada field tersebut.
 */
function bayarAssertRejected(Closure $callback, string $reason, ?string $field = null): void
{
    expect(__("pondok.payment.errors.{$reason}"))->not->toBe("pondok.payment.errors.{$reason}");

    try {
        $callback();
    } catch (ValidationException $exception) {
        $errors = $exception->errors();

        if ($field !== null) {
            expect($errors)->toHaveKey($field);
        }

        $matches = collect($errors)->flatten()->contains(fn (string $message): bool => preg_match(bayarMessagePattern($reason), $message) === 1);

        expect($matches)->toBeTrue("Pesan error tidak sesuai pondok.payment.errors.{$reason}: ".json_encode($errors));

        return;
    }

    test()->fail('ValidationException diharapkan, tetapi tidak dilempar.');
}

/**
 * Pembayaran 500.000 dari tagihan santri mukim SLTP (nomor KW-202610-00001), siap untuk uji kuitansi dan panel.
 *
 * @param  array<string, mixed>  $overrides
 */
function bayarPaymentFor(object $f, int $amount = 500000, array $overrides = [], ?User $by = null): Payment
{
    return bayarRecord(bayarInvoice($f), $amount, $overrides, $by);
}

/**
 * Teks polos dari HTML: tag dibuang, entitas didekode, spasi (termasuk spasi tak-putus) dirapikan.
 */
function bayarHtmlText(string $html): string
{
    $html = (string) preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $text));
}

/*
|--------------------------------------------------------------------------
| Helper laporan keuangan (F008)
|--------------------------------------------------------------------------
|
| Berawalan `laporan`. Memakai `tagihanFixture`, `tagihanStudent`, `tagihanInvoice`,
| `keuanganUser`. Waktu uji bawaan: 2026-10-15 10:00 Asia/Jakarta (diatur test pemanggil).
|
*/

/**
 * Fixture tanpa tarif: tahun ajaran 2026/2027, jenjang SLTP dan SLTA, jenis biaya SPP/ASRAMA/MAKAN.
 */
function laporanFixture(): object
{
    return tagihanFixture(withRates: false);
}

/**
 * Santri aktif jenjang `sltp` atau `slta`.
 *
 * @param  array<string, mixed>  $attributes
 */
function laporanStudent(object $f, string $unit = 'sltp', array $attributes = []): Student
{
    return tagihanStudent($f, attributes: $attributes, cohort: $unit === 'slta' ? $f->cohortSlta : $f->cohort);
}

/**
 * Tagihan lewat factory dengan status turunan dari `paid` dan `total` bila `$status` kosong.
 * `$dueOn` bawaan: tanggal 10 periode.
 *
 * @param  array<string, mixed>  $attributes
 */
function laporanInvoice(object $f, Student $student, string $period, int $total, int $paid = 0, ?string $status = null, ?string $dueOn = null, string $group = 'BULANAN', array $attributes = []): Invoice
{
    $status ??= match (true) {
        $paid >= $total => Invoice::STATUS_PAID,
        $paid === 0 => Invoice::STATUS_UNPAID,
        default => Invoice::STATUS_PARTIAL,
    };

    return tagihanInvoice($student, $f->year, [
        'period' => CarbonImmutable::parse($period, 'Asia/Jakarta')->startOfMonth(),
        'total' => $total,
        'paid_total' => $paid,
        'status' => $status,
        'billing_group' => $group,
        ...($dueOn !== null ? ['due_on' => $dueOn] : []),
        ...$attributes,
    ]);
}

/**
 * Item tagihan snapshot; `amount = base - discount`.
 */
function laporanItem(Invoice $invoice, FeeType $type, int $base, int $discount = 0, ?FeeOverride $override = null): InvoiceItem
{
    return InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'fee_type_id' => $type->id,
        'base_amount' => $base,
        'discount_amount' => $discount,
        'amount' => $base - $discount,
        'fee_override_id' => $override?->id,
    ]);
}

function laporanScholarship(string $name, ?string $sponsor = null): Scholarship
{
    return Scholarship::factory()->create(['name' => $name, 'sponsor' => $sponsor]);
}

function laporanOverride(Student $student, FeeType $type, ?Scholarship $scholarship = null): FeeOverride
{
    return FeeOverride::factory()->create([
        'student_id' => $student->id,
        'fee_type_id' => $type->id,
        'scholarship_id' => $scholarship?->id,
    ]);
}

/**
 * Pembayaran lewat factory (tanpa alokasi dan tanpa mengubah `paid_total`).
 *
 * @param  array<string, mixed>  $attributes
 */
function laporanPayment(Invoice $invoice, int $amount, string $paidOn, string $method = 'tunai', array $attributes = []): Payment
{
    return Payment::factory()->create([
        'invoice_id' => $invoice->id,
        'amount' => $amount,
        'paid_on' => $paidOn,
        'method' => $method,
        ...$attributes,
    ]);
}

/**
 * Filter laporan lewat `FinanceReportFilter::make` (bulan `Y-m`; `$to` bawaan = `$from`).
 */
function laporanFilter(string $from = '2026-10', ?string $to = null, ?int $unitId = null, ?string $group = null): FinanceReportFilter
{
    return FinanceReportFilter::make($from, $to ?? $from, $unitId, $group);
}

/**
 * Pola regex untuk pesan `pondok.finance_report.errors.<reason>`; placeholder `:nama` bebas isi.
 */
function laporanMessagePattern(string $reason): string
{
    $parts = preg_split('/:[A-Za-z_]+/', __("pondok.finance_report.errors.{$reason}"));

    return '/^'.implode('.+', array_map(fn (string $part): string => preg_quote($part, '/'), $parts)).'$/su';
}

/**
 * Pastikan callback melempar ValidationException dengan pesan `pondok.finance_report.errors.<reason>` pada `$field`.
 */
function laporanAssertRejected(Closure $callback, string $reason, string $field): void
{
    expect(__("pondok.finance_report.errors.{$reason}"))->not->toBe("pondok.finance_report.errors.{$reason}");

    try {
        $callback();
    } catch (ValidationException $exception) {
        $errors = $exception->errors();

        expect($errors)->toHaveKey($field);

        $matches = collect($errors[$field])->contains(fn (string $message): bool => preg_match(laporanMessagePattern($reason), $message) === 1);

        expect($matches)->toBeTrue("Pesan error tidak sesuai pondok.finance_report.errors.{$reason}: ".json_encode($errors));

        return;
    }

    test()->fail('ValidationException diharapkan, tetapi tidak dilempar.');
}

/**
 * Tagihan dengan item per kode jenis biaya (SPP, ASRAMA, MAKAN) sehingga bisa dibayar lewat `RecordPayment`.
 *
 * @param  array<string, int>  $amounts  kode jenis biaya => nominal
 */
function laporanInvoiceWithItems(object $f, Student $student, array $amounts, string $period = '2026-10-01', string $group = 'BULANAN'): Invoice
{
    $types = ['SPP' => $f->spp, 'ASRAMA' => $f->asrama, 'MAKAN' => $f->makan];
    $invoice = laporanInvoice($f, $student, $period, array_sum($amounts), 0, null, null, $group);

    foreach ($amounts as $code => $amount) {
        laporanItem($invoice, $types[$code], $amount);
    }

    return $invoice;
}

/**
 * Fixture AC-11: Tahfidz (A 250.000 SPP, B 500.000), Yatim (A 300.000 MAKAN), Anak Ustadz (C 100.000),
 * keringanan tanpa beasiswa (D 50.000), diskon di tagihan batal (E 400.000), diskon bulan September (F 999.000).
 */
function laporanSubsidyFixture(object $f): object
{
    $tahfidz = laporanScholarship('Beasiswa Tahfidz', 'Yayasan Contoh');
    $yatim = laporanScholarship('Yatim', 'Yayasan Contoh');
    $ustadz = laporanScholarship('Anak Ustadz');
    laporanScholarship('Program Kosong', 'Yayasan Contoh');

    $a = laporanStudent($f);
    $b = laporanStudent($f);
    $c = laporanStudent($f);
    $d = laporanStudent($f);
    $e = laporanStudent($f);
    $g = laporanStudent($f);

    $invoiceA = laporanInvoice($f, $a, '2026-10-01', 450000);
    $tahfidzA = laporanOverride($a, $f->spp, $tahfidz);
    laporanItem($invoiceA, $f->spp, 500000, 250000, $tahfidzA);
    laporanItem($invoiceA, $f->makan, 500000, 300000, laporanOverride($a, $f->makan, $yatim));

    $tahfidzB = laporanOverride($b, $f->spp, $tahfidz);
    laporanItem(laporanInvoice($f, $b, '2026-10-01', 0), $f->spp, 500000, 500000, $tahfidzB);

    laporanItem(laporanInvoice($f, $c, '2026-10-01', 400000), $f->spp, 500000, 100000, laporanOverride($c, $f->spp, $ustadz));
    laporanItem(laporanInvoice($f, $d, '2026-10-01', 450000), $f->spp, 500000, 50000, laporanOverride($d, $f->spp));
    laporanItem(laporanInvoice($f, $e, '2026-10-01', 100000, 0, Invoice::STATUS_CANCELLED), $f->spp, 500000, 400000, laporanOverride($e, $f->spp, $tahfidz));
    laporanItem(laporanInvoice($f, $g, '2026-09-01', 1000), $f->spp, 1000000, 999000, laporanOverride($g, $f->spp, $tahfidz));
    // Item tanpa override tidak dihitung walau ada diskon.
    laporanItem(laporanInvoice($f, laporanStudent($f), '2026-10-01', 300000), $f->spp, 500000, 200000);

    return (object) ['tahfidz' => $tahfidz, 'tahfidzA' => $tahfidzA, 'a' => $a];
}

/**
 * Fixture AC-14: santri mukim (1.200.000 dibayar 600.000 tunai 15 Okt), santri SLTP kelompok LAIN (SPP 500.000, transfer 150.000
 * 14 Okt), santri SLTA (SPP 500.000, tunai 70.000 15 Okt), dan satu pembayaran yang dibatalkan.
 */
function laporanCashFixture(object $f): void
{
    $first = laporanInvoiceWithItems($f, laporanStudent($f), ['MAKAN' => 400000, 'ASRAMA' => 300000, 'SPP' => 500000]);
    $second = laporanInvoiceWithItems($f, laporanStudent($f), ['SPP' => 500000], group: 'LAIN');
    $third = laporanInvoiceWithItems($f, laporanStudent($f, 'slta'), ['SPP' => 500000], group: 'SLTA');
    $fourth = laporanInvoiceWithItems($f, laporanStudent($f), ['MAKAN' => 400000], group: 'LAIN2');

    bayarRecord($first, 600000);
    bayarRecord($second, 150000, ['method' => 'transfer', 'paid_on' => '2026-10-14']);
    bayarRecord($third, 70000);
    $cancelled = bayarRecord($fourth, 100000);
    app(CancelPayment::class)->handle($cancelled, User::factory()->create(), 'Salah input');
}

/*
|--------------------------------------------------------------------------
| Helper master PSB (F009)
|--------------------------------------------------------------------------
|
| Berawalan `psb`. Memakai `keuanganUser` untuk membuat user berperan.
| Seeder peran harus sudah dijalankan oleh test pemanggil bila butuh user.
|
*/

/**
 * User dengan peran, opsional dibatasi ke jenjang (kode unit, mis. 'SLTP').
 *
 * @param  list<string>  $unitCodes
 */
function psbUser(string $role, array $unitCodes = []): User
{
    return keuanganUser($role, $unitCodes);
}

/**
 * Fixture: jenjang SD, SLTP, SLTA (UnitSeeder), 7 jenis dokumen (DocumentTypeSeeder), tahun ajaran
 * 2026/2027 (aktif, 2026-07-01..2027-06-30) dan 2027/2028, beasiswa aktif `Beasiswa Tahfidz` (persen 50)
 * dan nonaktif `Beasiswa Lama`. `types` diindeks kode jenis dokumen.
 *
 * @return object{sd: Unit, sltp: Unit, slta: Unit, y2026: AcademicYear, y2027: AcademicYear, tahfidz: Scholarship, lama: Scholarship, types: Collection<string, DocumentType>}
 */
function psbFixture(): object
{
    test()->seed([UnitSeeder::class, DocumentTypeSeeder::class]);

    return (object) [
        'sd' => Unit::query()->where('code', 'SD')->firstOrFail(),
        'sltp' => Unit::query()->where('code', 'SLTP')->firstOrFail(),
        'slta' => Unit::query()->where('code', 'SLTA')->firstOrFail(),
        'y2026' => AcademicYear::factory()->active()->create(['name' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']),
        'y2027' => AcademicYear::factory()->create(['name' => '2027/2028', 'starts_on' => '2027-07-01', 'ends_on' => '2028-06-30']),
        'tahfidz' => Scholarship::factory()->percent(50)->create(['name' => 'Beasiswa Tahfidz']),
        'lama' => Scholarship::factory()->inactive()->create(['name' => 'Beasiswa Lama']),
        'types' => DocumentType::query()->get()->keyBy('code'),
    ];
}

/**
 * Gelombang langsung lewat factory (tanpa Action, jadi tanpa validasi irisan).
 *
 * @param  array<string, mixed>  $attributes
 */
function psbWave(AcademicYear $year, string $name = 'Gelombang 1', string $opens = '2027-01-01', string $closes = '2027-03-31', array $attributes = []): AdmissionWave
{
    return AdmissionWave::factory()->create([
        'academic_year_id' => $year->id,
        'name' => $name,
        'opens_on' => $opens,
        'closes_on' => $closes,
        ...$attributes,
    ]);
}

/**
 * Jenis biaya PSB (frekuensi `saat_daftar` atau `saat_diterima`).
 *
 * @param  array<string, mixed>  $attributes
 */
function psbFeeType(string $frequency = 'saat_daftar', array $attributes = []): FeeType
{
    return FeeType::factory()->create(['frequency' => $frequency, 'billing_group' => 'PSB', ...$attributes]);
}

/**
 * Data masukan SaveAdmissionWave yang valid (Y2027, 2027-01-01..2027-03-31, kuota 150, tanpa `is_open`).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function psbWaveData(object $f, array $overrides = []): array
{
    return [
        'academic_year_id' => $f->y2027->id,
        'name' => 'Gelombang 1',
        'opens_on' => '2027-01-01',
        'closes_on' => '2027-03-31',
        'quota' => 150,
        ...$overrides,
    ];
}

/**
 * Data masukan SaveAdmissionTrack yang valid (beasiswa tahfidz).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function psbTrackData(object $f, array $overrides = []): array
{
    return [
        'code' => 'TAHFIDZ_2',
        'name' => 'Tahfidz Lanjutan',
        'scholarship_id' => $f->tahfidz->id,
        'terms' => 'Hafal 5 juz',
        ...$overrides,
    ];
}

/**
 * Data masukan SaveAdmissionRequirement yang valid (KK untuk SD).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function psbRequirementData(object $f, array $overrides = []): array
{
    return [
        'unit_id' => $f->sd->id,
        'document_type_id' => $f->types['KK']->id,
        'name' => 'Kartu Keluarga',
        'sort_order' => 0,
        ...$overrides,
    ];
}

/**
 * Pola regex untuk pesan `pondok.psb.errors.<reason>`; placeholder `:nama` bebas isi.
 */
function psbMessagePattern(string $reason): string
{
    $parts = preg_split('/:[A-Za-z_]+/', __("pondok.psb.errors.{$reason}"));

    return '/^'.implode('.+', array_map(fn (string $part): string => preg_quote($part, '/'), $parts)).'$/su';
}

/**
 * Pastikan callback melempar ValidationException dengan pesan kunci lang `pondok.psb.errors.<reason>` pada `$field`.
 */
function psbAssertRejected(Closure $callback, string $reason, string $field): void
{
    expect(__("pondok.psb.errors.{$reason}"))->not->toBe("pondok.psb.errors.{$reason}");

    try {
        $callback();
    } catch (ValidationException $exception) {
        $errors = $exception->errors();

        expect($errors)->toHaveKey($field);

        $matches = collect($errors[$field])->contains(fn (string $message): bool => preg_match(psbMessagePattern($reason), $message) === 1);

        expect($matches)->toBeTrue("Pesan error tidak sesuai pondok.psb.errors.{$reason}: ".json_encode($errors));

        return;
    }

    test()->fail('ValidationException diharapkan, tetapi tidak dilempar.');
}

/*
|--------------------------------------------------------------------------
| Helper dashboard (F018)
|--------------------------------------------------------------------------
|
| Berawalan `dashboard`. Memakai `tagihanFixture`, `laporanStudent`, `laporanInvoice`,
| `laporanPayment`, `bayarHtmlText`. Waktu uji bawaan: 2026-10-15 10:00 Asia/Jakarta.
|
*/

/**
 * Seed peran dan saklar, aktifkan panel admin, bekukan waktu, lalu siapkan fixture tanpa tarif
 * (tahun ajaran aktif 2026/2027, jenjang SD/SLTP/SLTA, angkatan 2026 untuk SLTP dan SLTA).
 *
 * @return object{year: AcademicYear, sd: Unit, sltp: Unit, slta: Unit, cohort: Cohort, cohortSlta: Cohort, spp: FeeType}
 */
function dashboardBoot(): object
{
    test()->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    test()->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));

    $f = tagihanFixture(withRates: false);
    $f->sd = Unit::query()->where('code', 'SD')->firstOrFail();

    return $f;
}

/**
 * Widget F018 (kunci pendek => kelas).
 *
 * @return array<string, class-string>
 */
function dashboardWidgets(): array
{
    return [
        'finance' => 'App\\Filament\\Widgets\\FinanceOverviewWidget',
        'psb' => 'App\\Filament\\Widgets\\PsbOverviewWidget',
        'content' => 'App\\Filament\\Widgets\\ContentOverviewWidget',
        'students' => 'App\\Filament\\Widgets\\ActiveStudentsWidget',
        'documents' => 'App\\Filament\\Widgets\\DocumentCompletenessWidget',
        'achievements' => 'App\\Filament\\Widgets\\RecentAchievementsWidget',
        'system' => 'App\\Filament\\Widgets\\SystemOverviewWidget',
    ];
}

/**
 * Permission metrik tiap widget (kunci pendek => permission).
 *
 * @return array<string, string>
 */
function dashboardPermissions(): array
{
    return [
        'finance' => 'view-finance-metrics',
        'psb' => 'view-psb-metrics',
        'content' => 'view-content-metrics',
        'students' => 'view-student-metrics',
        'documents' => 'view-student-metrics',
        'achievements' => 'view-student-metrics',
        'system' => 'view-settings-metrics',
    ];
}

/**
 * Matriks peran => widget yang terlihat (plan F018, bagian Permission).
 *
 * @return array<string, list<string>>
 */
function dashboardMatrix(): array
{
    return [
        'super-admin' => ['finance', 'psb', 'content', 'students', 'documents', 'achievements', 'system'],
        'bendahara' => ['finance'],
        'admin-konten' => ['content'],
        'admin-sekolah' => ['students', 'documents', 'achievements', 'system'],
        'admin-psb' => ['psb'],
        'kesantrian' => ['students', 'documents', 'achievements'],
        'musyrif' => [],
        'ustadz-tahfidz' => [],
        'petugas-pos' => [],
        'wali-kelas' => [],
    ];
}

/**
 * User tanpa peran dengan permission tertentu saja, opsional dibatasi jenjang.
 *
 * @param  list<string>  $permissions
 * @param  list<string>  $unitCodes
 */
function dashboardUserWith(array $permissions, array $unitCodes = []): User
{
    $user = User::factory()->create()->givePermissionTo($permissions);

    foreach ($unitCodes as $code) {
        $user->units()->attach(Unit::query()->where('code', $code)->value('id'));
    }

    return $user;
}

/**
 * GET /admin sebagai user (harus 200).
 */
function dashboardGet(User $user): TestResponse
{
    return test()->actingAs($user)->get('/admin')->assertOk();
}

/**
 * Teks polos isi halaman `/admin` sebagai user.
 */
function dashboardText(User $user): string
{
    return bayarHtmlText(dashboardGet($user)->getContent());
}

/**
 * Teks polos satu widget (kunci pendek dari `dashboardWidgets()`) yang dirender untuk user.
 */
function dashboardWidgetText(User $user, string $key): string
{
    test()->actingAs($user);

    return bayarHtmlText(Livewire::test(dashboardWidgets()[$key])->html());
}

/**
 * Tepat tiga saklar modul aktif (akademik, keuangan, konten) dari tujuh.
 */
function dashboardEnableThreeModules(): void
{
    foreach (Module::cases() as $module) {
        Setting::set("modules.{$module->value}.enabled", in_array($module->value, ['akademik', 'keuangan', 'konten'], true));
    }
}

/**
 * Data AC-7 (bulan berjalan Oktober 2026). Tagihan C milik santri `$unitOfC` ('sltp' atau 'slta'), sisanya SLTP.
 * Oktober: A 500.000 lunas (bayar 1 Okt), B 300.000 sebagian 100.000 jatuh tempo 10 Okt (bayar 31 Okt),
 * C 200.000 belum jatuh tempo 20 Okt (pembayaran 50.000 dibatalkan), D 400.000 batal.
 * September: 999.000 sebagian 70.000 (bayar 30 Sep).
 *
 * @return object{a: Invoice, b: Invoice, c: Invoice, d: Invoice, september: Invoice}
 */
function dashboardFinanceData(object $f, string $unitOfC = 'sltp'): object
{
    $a = laporanInvoice($f, laporanStudent($f), '2026-10-01', 500000, 500000);
    $b = laporanInvoice($f, laporanStudent($f), '2026-10-01', 300000, 100000, null, '2026-10-10');
    $c = laporanInvoice($f, laporanStudent($f, $unitOfC), '2026-10-01', 200000, 0, null, '2026-10-20');
    $d = laporanInvoice($f, laporanStudent($f), '2026-10-01', 400000, 0, Invoice::STATUS_CANCELLED);
    $september = laporanInvoice($f, laporanStudent($f), '2026-09-01', 999000, 70000);

    laporanPayment($a, 500000, '2026-10-01');
    laporanPayment($b, 100000, '2026-10-31');
    laporanPayment($c, 50000, '2026-10-12', 'tunai', [
        'status' => Payment::STATUS_CANCELLED,
        'cancelled_at' => now(),
        'cancelled_by' => User::factory()->create()->id,
        'cancel_reason' => 'Salah input',
    ]);
    laporanPayment($september, 70000, '2026-09-30');

    return (object) ['a' => $a, 'b' => $b, 'c' => $c, 'd' => $d, 'september' => $september];
}

/**
 * Data AC-11. SLTP: angkatan 2025 dua aktif, angkatan 2026 tiga aktif, satu `lulus`, satu aktif yang diarsipkan.
 * SLTA: angkatan 2026 satu aktif. SD: tanpa santri.
 *
 * @return object{cohort2025: Cohort, cohort2026: Cohort, cohortSlta: Cohort}
 */
function dashboardStudentData(object $f): object
{
    $cohort2025 = Cohort::factory()->create(['unit_id' => $f->sltp->id, 'entry_year' => 2025, 'name' => 'Angkatan 2025']);

    Student::factory()->count(2)->forCohort($cohort2025)->create();
    Student::factory()->count(3)->forCohort($f->cohort)->create();
    Student::factory()->forCohort($f->cohort)->create(['status' => 'lulus']);
    Student::factory()->forCohort($f->cohort)->create()->delete();
    Student::factory()->forCohort($f->cohortSlta)->create();

    return (object) ['cohort2025' => $cohort2025, 'cohort2026' => $f->cohort, 'cohortSlta' => $f->cohortSlta];
}

/**
 * Jumlah query yang dijalankan callback.
 */
function dashboardQueryCount(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/*
|--------------------------------------------------------------------------
| Helper pendaftaran PSB (F010)
|--------------------------------------------------------------------------
|
| Berawalan `pendaftaran`. Memakai `psbFixture`, `psbWave`, `psbFeeType`, `keuanganRate`,
| `keuanganUser`, `bayarRecord`. Waktu uji bawaan: 2026-10-15 10:00 Asia/Jakarta.
| Kunci sesi halaman status (kontrak rencana): `psb.applicant_id`, `psb.token_fingerprint`.
|
*/

/**
 * Token polos bawaan `pendaftaranApplicant()`.
 */
function pendaftaranToken(): string
{
    return 'ABCD-EFGH-JK23';
}

/**
 * Fixture PSB: jenjang, jenis dokumen, jalur bawaan, tahun ajaran 2026/2027 dan 2027/2028, gelombang W1
 * (Y2027, "Gelombang 1", 2026-10-01..2026-12-31, terbuka, tanpa kuota), jenis biaya FORMULIR (`saat_daftar`,
 * grup PSB_DAFTAR, prioritas 10) dengan tarif SLTP Rp250.000 berlaku Y2027, syarat KK/AKTA (semua jenjang, wajib),
 * RAPOR (SLTP, opsional), IJAZAH (SD, wajib), saklar modul bawaan, waktu dibekukan, dan disk private/public palsu.
 *
 * @return object{sd: Unit, sltp: Unit, slta: Unit, y2026: AcademicYear, y2027: AcademicYear, types: Collection<string, DocumentType>, reguler: AdmissionTrack, wave: AdmissionWave, formulir: FeeType, kk: AdmissionRequirement, akta: AdmissionRequirement, rapor: AdmissionRequirement, ijazah: AdmissionRequirement}
 */
function pendaftaranFixture(): object
{
    test()->seed([SettingSeeder::class, AdmissionTrackSeeder::class]);
    test()->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    Storage::fake('private');
    Storage::fake('public');

    $f = psbFixture();
    $f->reguler = AdmissionTrack::query()->where('code', 'REGULER')->firstOrFail();
    $f->wave = psbWave($f->y2027, 'Gelombang 1', '2026-10-01', '2026-12-31');
    $f->formulir = psbFeeType('saat_daftar', ['code' => 'FORMULIR', 'name' => 'Formulir pendaftaran', 'billing_group' => 'PSB_DAFTAR', 'priority' => 10]);
    keuanganRate($f->formulir, $f->y2027, $f->sltp, 250000);
    $f->kk = AdmissionRequirement::factory()->create(['unit_id' => null, 'document_type_id' => $f->types['KK']->id, 'name' => 'Kartu Keluarga', 'is_required' => true, 'sort_order' => 1]);
    $f->akta = AdmissionRequirement::factory()->create(['unit_id' => null, 'document_type_id' => $f->types['AKTA']->id, 'name' => 'Akta kelahiran', 'is_required' => true, 'sort_order' => 2]);
    $f->rapor = AdmissionRequirement::factory()->create(['unit_id' => $f->sltp->id, 'document_type_id' => $f->types['RAPOR']->id, 'name' => 'Rapor', 'is_required' => false, 'sort_order' => 3]);
    $f->ijazah = AdmissionRequirement::factory()->create(['unit_id' => $f->sd->id, 'document_type_id' => $f->types['IJAZAH']->id, 'name' => 'Ijazah', 'is_required' => true, 'sort_order' => 4]);

    return $f;
}

/**
 * Data masukan SubmitApplication yang valid (SLTP, jalur REGULER, gelombang "Gelombang 1").
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pendaftaranData(array $overrides = []): array
{
    return [
        'unit_id' => Unit::query()->where('code', 'SLTP')->value('id'),
        'admission_track_id' => AdmissionTrack::query()->where('code', 'REGULER')->value('id'),
        'admission_wave_id' => AdmissionWave::query()->where('name', 'Gelombang 1')->value('id'),
        'name' => ' Ahmad  Fauzan ',
        'gender' => 'L',
        'birth_place' => 'Bandung',
        'birth_date' => '2014-05-12',
        'nisn' => '0123456789',
        'previous_school' => 'SDIT Al-Fatih',
        'guardian_name' => 'Budi Santoso',
        'guardian_phone' => '+62 812-3456-7890',
        'email' => 'Budi@Example.com',
        'consent' => true,
        ...$overrides,
    ];
}

/**
 * Kirim pendaftaran lewat Action.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{applicant: Applicant, token: string}
 */
function pendaftaranSubmit(array $overrides = []): array
{
    return app(SubmitApplication::class)->handle(pendaftaranData($overrides));
}

/**
 * Pendaftar langsung lewat factory (SLTP, REGULER, "Gelombang 1", status `diajukan`), token bawaan `pendaftaranToken()`.
 *
 * @param  array<string, mixed>  $attributes
 */
function pendaftaranApplicant(array $attributes = [], ?string $token = null): Applicant
{
    $defaults = [
        'unit_id' => Unit::query()->where('code', 'SLTP')->value('id'),
        'admission_track_id' => AdmissionTrack::query()->where('code', 'REGULER')->value('id'),
        'admission_wave_id' => AdmissionWave::query()->where('name', 'Gelombang 1')->value('id'),
    ];

    return Applicant::factory()->withToken($token ?? pendaftaranToken())->create([...$defaults, ...$attributes]);
}

/**
 * Buat tagihan pendaftaran (bila belum ada) dan lunasi semuanya lewat `RecordPayment`.
 */
function pendaftaranPayRegistration(Applicant $applicant): void
{
    app(GenerateRegistrationInvoices::class)->handle($applicant);

    $applicant->invoices()->withoutGlobalScopes()->where('status', '<>', Invoice::STATUS_CANCELLED)->get()
        ->each(function (Invoice $invoice): void {
            if ($invoice->remainingAmount() > 0) {
                bayarRecord($invoice, $invoice->remainingAmount());
            }
        });
}

/**
 * Berkas pendaftar langsung lewat factory, dengan file isi di disk private bila `$withFile`.
 *
 * @param  array<string, mixed>  $attributes
 */
function pendaftaranDocument(Applicant $applicant, AdmissionRequirement $requirement, array $attributes = [], bool $withFile = true): ApplicantDocument
{
    $path = "psb/applicants/{$applicant->id}/".fake()->uuid().'.pdf';

    if ($withFile) {
        Storage::disk('private')->put($path, '%PDF-1.4 isi berkas');
    }

    return ApplicantDocument::factory()->create([
        'applicant_id' => $applicant->id,
        'admission_requirement_id' => $requirement->id,
        'file_path' => $path,
        'original_name' => 'kk.pdf',
        'mime_type' => 'application/pdf',
        'size_kb' => 1,
        ...$attributes,
    ]);
}

/**
 * Sesi halaman status yang valid untuk pendaftar (kunci sesi dari rencana F010).
 *
 * @return array<string, mixed>
 */
function pendaftaranSession(Applicant $applicant): array
{
    return ['psb.applicant_id' => $applicant->id, 'psb.token_fingerprint' => $applicant->tokenFingerprint()];
}

/**
 * Berkas unggahan palsu PDF (`$kb` KB) bernama `$name`.
 */
function pendaftaranPdf(string $name = 'kk.pdf', int $kb = 100): UploadedFile
{
    return UploadedFile::fake()->create($name, $kb, 'application/pdf');
}

/**
 * Pola regex untuk token pendaftar `XXXX-XXXX-XXXX`.
 */
function pendaftaranTokenPattern(): string
{
    return '/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/';
}

/**
 * Pastikan callback melempar ValidationException dengan pesan `pondok.psb.errors.<reason>`.
 * Bila `$field` diisi, error harus ada pada field tersebut.
 */
function pendaftaranAssertRejected(Closure $callback, string $reason, ?string $field = null): void
{
    expect(__("pondok.psb.errors.{$reason}"))->not->toBe("pondok.psb.errors.{$reason}");

    try {
        $callback();
    } catch (ValidationException $exception) {
        $errors = $exception->errors();

        if ($field !== null) {
            expect($errors)->toHaveKey($field);
        }

        $matches = collect($errors)->flatten()->contains(fn (string $message): bool => preg_match(psbMessagePattern($reason), $message) === 1);

        expect($matches)->toBeTrue("Pesan error tidak sesuai pondok.psb.errors.{$reason}: ".json_encode($errors));

        return;
    }

    test()->fail('ValidationException diharapkan, tetapi tidak dilempar.');
}

/**
 * Struktur tabel `students`, `fee_overrides`, `invoices`, dan FK tabel anak setelah migration FK `applicant_id`
 * (AC-3 dan AC-4 F010): FK baru, index, unique, kolom generated, dan FK lain tetap utuh dan `restrict`.
 */
function pendaftaranAssertForeignKeyStructure(): void
{
    $foreignKey = fn (string $table, string $column) => collect(Schema::getForeignKeys($table))->first(fn (array $key): bool => $key['columns'] === [$column]);
    $indexColumns = fn (string $table) => collect(Schema::getIndexes($table))->pluck('columns')->all();
    $uniqueColumns = fn (string $table) => collect(Schema::getIndexes($table))->where('unique', true)->pluck('columns')->all();

    foreach (['students', 'fee_overrides', 'invoices'] as $table) {
        $key = $foreignKey($table, 'applicant_id');

        expect($key)->not->toBeNull("FK applicant_id di {$table}")
            ->and($key['foreign_table'])->toBe('applicants')
            ->and($key['foreign_columns'])->toBe(['id'])
            ->and(strtolower($key['on_delete']))->toBe('restrict')
            ->and($indexColumns($table))->toContain(['applicant_id']);
    }

    expect(Schema::hasColumn('students', 'deleted_at'))->toBeTrue()
        ->and($uniqueColumns('students'))->toContain(['nis'])->toContain(['nisn'])
        ->and($indexColumns('students'))->toContain(['unit_id', 'status'])
        ->and($indexColumns('fee_overrides'))->toContain(['student_id', 'fee_type_id', 'valid_from']);

    foreach ([['students', 'unit_id'], ['students', 'cohort_id'], ['fee_overrides', 'student_id'], ['fee_overrides', 'fee_type_id'], ['fee_overrides', 'scholarship_id'], ['fee_overrides', 'approved_by'],
        ['invoices', 'student_id'], ['invoices', 'academic_year_id'], ['invoices', 'generated_by'],
        ['invoice_items', 'invoice_id'], ['payments', 'invoice_id']] as [$table, $column]) {
        $key = $foreignKey($table, $column);

        expect($key)->not->toBeNull("FK {$column} di {$table}")
            ->and(strtolower($key['on_delete']))->toBe('restrict');
    }

    // Migration lama (sudah di-commit): enrollments dan room_assignments memakai cascadeOnDelete ke students.
    foreach ([['enrollments', 'student_id'], ['room_assignments', 'student_id']] as [$table, $column]) {
        expect(strtolower($foreignKey($table, $column)['on_delete']))->toBe('cascade');
    }

    foreach ([['invoice_items', 'invoice_id', 'invoices'], ['payments', 'invoice_id', 'invoices'], ['enrollments', 'student_id', 'students'],
        ['room_assignments', 'student_id', 'students'], ['fee_overrides', 'student_id', 'students'], ['invoices', 'student_id', 'students']] as [$table, $column, $parent]) {
        expect($foreignKey($table, $column)['foreign_table'])->toBe($parent);
    }

    $marker = collect(Schema::getColumns('invoices'))->firstWhere('name', 'active_marker');
    $indexes = collect(Schema::getIndexes('invoices'));

    expect($marker)->not->toBeNull()->and($marker['generation'])->not->toBeNull()
        ->and($indexes->firstWhere('name', 'invoices_student_period_group_active_unique'))->toMatchArray(['unique' => true, 'columns' => ['student_id', 'period', 'billing_group', 'active_marker']])
        ->and($indexes->firstWhere('name', 'invoices_applicant_group_active_unique'))->toMatchArray(['unique' => true, 'columns' => ['applicant_id', 'billing_group', 'active_marker']])
        ->and($uniqueColumns('invoices'))->toContain(['invoice_no'])
        ->and($indexColumns('invoices'))->toContain(['status', 'period']);
}

/**
 * Ganti `GenerateApplicantNumber` di container dengan palsu: nomor `$forced` dikembalikan lebih dulu (satu per
 * panggilan), lalu `$always` bila diisi, selain itu perilaku asli. `calls` menghitung panggilan.
 *
 * @param  list<string>  $forced
 */
function pendaftaranFakeApplicantNumber(array $forced = [], ?string $always = null): object
{
    $real = app(GenerateApplicantNumber::class);
    $fake = new class($real, $forced, $always) extends GenerateApplicantNumber
    {
        public int $calls = 0;

        /** @param list<string> $forced */
        public function __construct(private GenerateApplicantNumber $real, private array $forced, private ?string $always) {}

        public function handle(AdmissionWave $wave): string
        {
            $this->calls++;

            return array_shift($this->forced) ?? $this->always ?? $this->real->handle($wave);
        }
    };

    app()->instance(GenerateApplicantNumber::class, $fake);

    return $fake;
}

/**
 * Ganti `GenerateInvoiceNumber` di container dengan palsu (aturan sama seperti `pendaftaranFakeApplicantNumber`).
 *
 * @param  list<string>  $forced
 */
function pendaftaranFakeInvoiceNumber(array $forced = [], ?string $always = null): object
{
    $real = app(GenerateInvoiceNumber::class);
    $fake = new class($real, $forced, $always) extends GenerateInvoiceNumber
    {
        public int $calls = 0;

        /** @param list<string> $forced */
        public function __construct(private GenerateInvoiceNumber $real, private array $forced, private ?string $always) {}

        public function handle(CarbonInterface $month): string
        {
            $this->calls++;

            return array_shift($this->forced) ?? $this->always ?? $this->real->handle($month);
        }
    };

    app()->instance(GenerateInvoiceNumber::class, $fake);

    return $fake;
}

/**
 * Berkas unggahan sungguhan dengan isi `$content` (tipe MIME dibaca dari isi, bukan dari nama).
 */
function pendaftaranRealFile(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'pendaftaran');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

/**
 * Isi wizard pendaftaran sampai langkah 4 (periksa & kirim). Kunci `$overrides` memakai nama field
 * `pendaftaranData`; `consent` diisi hanya bila bernilai true. Kontrak komponen `RegistrationForm`:
 * properti publik bernama sama dengan field Action, `step`, honeypot `website`, aksi `next`, `back`, `submit`.
 *
 * @param  array<string, mixed>  $overrides
 */
function pendaftaranFillWizard(Testable $component, array $overrides = []): Testable
{
    $data = pendaftaranData($overrides);

    $component->set('unit_id', $data['unit_id'])->set('admission_track_id', $data['admission_track_id']);

    if (blank($component->get('admission_wave_id'))) {
        $component->set('admission_wave_id', $data['admission_wave_id']);
    }

    $component->call('next');

    foreach (['name', 'gender', 'birth_place', 'birth_date', 'nisn', 'previous_school'] as $field) {
        $component->set($field, $data[$field] ?? '');
    }

    $component->call('next');

    foreach (['guardian_name', 'guardian_phone', 'email'] as $field) {
        $component->set($field, $data[$field] ?? '');
    }

    $component->call('next');

    if (($data['consent'] ?? false) === true) {
        $component->set('consent', true);
    }

    return $component;
}

/**
 * Pastikan teks halaman memuat pesan `pondok.psb.errors.<reason>` (placeholder `:nama` bebas isi).
 */
function pendaftaranSeeMessage(Testable $component, string $reason): bool
{
    $message = __("pondok.psb.errors.{$reason}");
    expect($message)->not->toBe("pondok.psb.errors.{$reason}");

    $pattern = '/'.implode('.+?', array_map(fn (string $part): string => preg_quote($part, '/'), preg_split('/:[A-Za-z_]+/', $message))).'/su';

    return preg_match($pattern, tagihanPlainText($component)) === 1;
}

/*
|--------------------------------------------------------------------------
| Helper penerimaan PSB (F011)
|--------------------------------------------------------------------------
|
| Berawalan `seleksi`. Memakai `pendaftaranFixture`, `pendaftaranApplicant`, `pendaftaranPayRegistration`,
| `pendaftaranDocument`, `keuanganRate`, `psbFeeType`, `psbUser`. Waktu uji bawaan: 2026-11-20 10:00 Asia/Jakarta.
| Tagihan formulir pendaftar dibuat pada 2026-10-15 (nomor `INV-202610-NNNNN`) sehingga nomor tagihan penerimaan
| November mulai dari `INV-202611-00001`. Kontrak nama aksi panel `ViewApplicant`: `verify`, `accept`, `reserve`,
| `return`, `reject`, `reopen`, `cancelAcceptance`, `generateAcceptanceInvoices` dengan field catatan `review_note`.
|
*/

/**
 * Tambahan fixture F011 di atas hasil `pendaftaranFixture()`: jenis biaya UANG_PANGKAL (`saat_diterima`, grup
 * PSB_TERIMA, prioritas 20), SERAGAM (PSB_TERIMA, 30), KITAB (PSB_KITAB, 40), SPP (`bulanan`, BULANAN, 50) dengan tarif
 * SLTP berlaku Y2027 (5.000.000, 750.000, 300.000, 400.000); program `Beasiswa Yatim` (nominal 0); jalur TAHFIDZ
 * ber-Beasiswa Tahfidz (persen 50) dengan pemetaan [UANG_PANGKAL, SPP] dan jalur YATIM ber-Beasiswa Yatim tanpa
 * pemetaan; peran di-seed; `user` = admin-psb (P). Tidak mengubah waktu.
 *
 * @return object{uangPangkal: FeeType, seragam: FeeType, kitab: FeeType, spp: FeeType, rates: array<string, FeeRate>, yatim: Scholarship, tahfidzTrack: AdmissionTrack, yatimTrack: AdmissionTrack, user: User}
 */
function seleksiExtend(object $f): object
{
    test()->seed(RolePermissionSeeder::class);

    $f->uangPangkal = psbFeeType('saat_diterima', ['code' => 'UANG_PANGKAL', 'name' => 'Uang pangkal', 'billing_group' => 'PSB_TERIMA', 'priority' => 20]);
    $f->seragam = psbFeeType('saat_diterima', ['code' => 'SERAGAM', 'name' => 'Seragam', 'billing_group' => 'PSB_TERIMA', 'priority' => 30]);
    $f->kitab = psbFeeType('saat_diterima', ['code' => 'KITAB', 'name' => 'Kitab', 'billing_group' => 'PSB_KITAB', 'priority' => 40]);
    $f->spp = psbFeeType('bulanan', ['code' => 'SPP', 'name' => 'SPP', 'billing_group' => 'BULANAN', 'priority' => 50]);
    $f->rates = [
        'UANG_PANGKAL' => keuanganRate($f->uangPangkal, $f->y2027, $f->sltp, 5000000),
        'SERAGAM' => keuanganRate($f->seragam, $f->y2027, $f->sltp, 750000),
        'KITAB' => keuanganRate($f->kitab, $f->y2027, $f->sltp, 300000),
        'SPP' => keuanganRate($f->spp, $f->y2027, $f->sltp, 400000),
    ];
    $f->yatim = Scholarship::factory()->nominal(0)->create(['name' => 'Beasiswa Yatim']);
    $f->tahfidzTrack = AdmissionTrack::query()->where('code', 'TAHFIDZ')->firstOrFail();
    $f->yatimTrack = AdmissionTrack::query()->where('code', 'YATIM')->firstOrFail();
    $f->tahfidzTrack->update(['scholarship_id' => $f->tahfidz->id]);
    $f->yatimTrack->update(['scholarship_id' => $f->yatim->id]);

    foreach ([$f->uangPangkal, $f->spp] as $type) {
        seleksiMapFeeType($f->tahfidzTrack, $type);
    }

    $f->user = psbUser('admin-psb');

    return $f;
}

/**
 * Fixture lengkap F011: `pendaftaranFixture()` + `seleksiExtend()` + waktu 2026-11-20 10:00 Asia/Jakarta.
 */
function seleksiFixture(): object
{
    $f = seleksiExtend(pendaftaranFixture());
    test()->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Asia/Jakarta'));

    return $f;
}

/**
 * Petakan jenis biaya ke jalur lewat tabel pivot `admission_track_fee_type` (langsung, tanpa relasi model).
 */
function seleksiMapFeeType(AdmissionTrack $track, FeeType $type): void
{
    DB::table('admission_track_fee_type')->insert([
        'admission_track_id' => $track->id,
        'fee_type_id' => $type->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Pendaftar di gelombang W1 dengan status, jalur (kode), dan jenjang (kode) tertentu. Dibuat `diajukan` pada
 * 2026-10-15 lalu status diubah langsung di database. `$complete` true: tagihan formulir (bila ada tarif) dilunasi dan
 * semua syarat wajib punya berkas `valid`; false: tagihan formulir (bila ada tarif) dibuat tetapi belum dibayar,
 * tanpa berkas. Waktu dikembalikan ke 2026-11-20 10:00.
 */
function seleksiApplicant(string $status = 'diverifikasi', string $track = 'REGULER', string $unit = 'SLTP', bool $complete = true): Applicant
{
    test()->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));

    $applicant = pendaftaranApplicant([
        'unit_id' => Unit::query()->where('code', $unit)->value('id'),
        'admission_track_id' => AdmissionTrack::query()->where('code', $track)->value('id'),
        'status' => Applicant::STATUS_SUBMITTED,
    ]);

    app(GenerateRegistrationInvoices::class)->handle($applicant);

    if ($complete) {
        pendaftaranPayRegistration($applicant);

        $applicant->requirements()->where('is_required', true)->get()
            ->each(fn (AdmissionRequirement $requirement) => pendaftaranDocument($applicant, $requirement, ['status' => 'valid'], false));
    }

    if ($status !== Applicant::STATUS_SUBMITTED) {
        Applicant::withoutGlobalScopes()->whereKey($applicant->id)->update(['status' => $status]);
    }

    test()->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Asia/Jakarta'));

    return Applicant::withoutGlobalScopes()->findOrFail($applicant->id);
}

/**
 * Jalankan Action keputusan (nama kelas tanpa namespace) sebagai `$by`. Catatan wajib dikirim sebagai string.
 */
function seleksiRun(string $action, Applicant $applicant, User $by, ?string $note = 'Catatan keputusan'): mixed
{
    test()->actingAs($by);

    return app("App\\Actions\\Psb\\{$action}")->handle($applicant, $by, $note);
}

/**
 * Terima pendaftar lewat `AcceptApplicant` sebagai `$by` (bawaan: admin-psb baru).
 *
 * @return array{applicant: Applicant, billing: array<string, mixed>|null, billing_failed: bool}
 */
function seleksiAccept(Applicant $applicant, ?User $by = null, ?string $note = null): array
{
    return seleksiRun('AcceptApplicant', $applicant, $by ?? psbUser('admin-psb'), $note);
}

/**
 * Tagihan pendaftar (semua kecuali `batal` bila `$includeCancelled` false), urut id, dengan item.
 *
 * @return Collection<int, Invoice>
 */
function seleksiInvoices(Applicant $applicant, bool $includeCancelled = false, ?string $group = null): Collection
{
    return Invoice::withoutUnitScope()->with('items.feeType')->where('applicant_id', $applicant->id)
        ->when(! $includeCancelled, fn ($query) => $query->where('status', '<>', Invoice::STATUS_CANCELLED))
        ->when($group, fn ($query, string $group) => $query->where('billing_group', $group))
        ->orderBy('id')->get();
}

/**
 * Item tagihan diindeks kode jenis biaya.
 *
 * @return Collection<string, InvoiceItem>
 */
function seleksiItems(Invoice $invoice): Collection
{
    return InvoiceItem::query()->where('invoice_id', $invoice->id)->with('feeType')->orderBy('id')->get()
        ->keyBy(fn (InvoiceItem $item): string => $item->feeType->code);
}

/**
 * Keringanan pendaftar urut id.
 *
 * @return Collection<int, FeeOverride>
 */
function seleksiOverrides(Applicant $applicant): Collection
{
    return FeeOverride::query()->with('feeType')->where('applicant_id', $applicant->id)->orderBy('id')->get();
}

/**
 * Activity log `psb` milik pendaftar, urut id.
 *
 * @return Collection<int, Activity>
 */
function seleksiActivities(Applicant $applicant): Collection
{
    return Activity::query()->where('log_name', 'psb')
        ->where('subject_type', $applicant->getMorphClass())->where('subject_id', $applicant->id)
        ->orderBy('id')->get();
}

/**
 * Potret keadaan pendaftar (keputusan, activity, tagihan, keringanan) untuk membuktikan "tanpa perubahan apa pun".
 *
 * @return array<string, mixed>
 */
function seleksiSnapshot(Applicant $applicant): array
{
    $row = Applicant::withoutGlobalScopes()->findOrFail($applicant->id);

    return [
        'status' => $row->status,
        'reviewed_by' => $row->reviewed_by,
        'reviewed_at' => $row->reviewed_at?->toDateTimeString(),
        'review_note' => $row->review_note,
        'activities' => Activity::query()->count(),
        'invoices' => Invoice::withoutUnitScope()->where('applicant_id', $applicant->id)->orderBy('id')->get(['id', 'status', 'total', 'paid_total'])->toArray(),
        'overrides' => FeeOverride::query()->where('applicant_id', $applicant->id)->orderBy('id')->get(['id', 'valid_from', 'valid_until', 'mode', 'value'])->toArray(),
    ];
}

/**
 * Apakah daftar `skipped` memuat jenis biaya (NULL untuk program nonaktif) dengan alasan tertentu.
 *
 * @param  list<array{fee_type_id: int|null, reason: string}>  $skipped
 */
function seleksiSkipped(array $skipped, ?int $feeTypeId, string $reason): bool
{
    return collect($skipped)->contains(fn (array $row): bool => $row['fee_type_id'] === $feeTypeId && $row['reason'] === $reason);
}

/**
 * Teks terjemahan yang dipastikan bukan kunci mentah.
 *
 * @param  array<string, mixed>  $replace
 */
function seleksiText(string $key, array $replace = []): string
{
    $text = __($key, $replace);

    expect($text)->toBeString()->not->toBe($key)->not->toBeEmpty();

    return $text;
}

/**
 * Livewire `ViewApplicant` untuk pendaftar sebagai user (bawaan: user yang sedang login).
 */
function seleksiView(Applicant $applicant, ?User $as = null): Testable
{
    if ($as !== null) {
        test()->actingAs($as);
    }

    return Livewire::test(ViewApplicant::class, ['record' => $applicant->id]);
}

/**
 * Semua teks notifikasi Filament pada request Livewire terakhir.
 */
function seleksiNotificationText(): string
{
    return asramaNotifications()->map(fn (array $notification): string => ($notification['title'] ?? '').' '.($notification['body'] ?? ''))->implode("\n");
}

/**
 * Apakah pesan `pondok.psb.errors.<reason>` terlihat di halaman komponen (galat form) atau notifikasi.
 */
function seleksiSeeMessage(Testable $component, string $reason): bool
{
    $message = seleksiText("pondok.psb.errors.{$reason}");
    $pattern = '/'.implode('.+?', array_map(fn (string $part): string => preg_quote($part, '/'), preg_split('/:[A-Za-z_]+/', $message))).'/su';
    $haystack = tagihanPlainText($component).' '.seleksiNotificationText().' '.collect($component->errors()->all())->implode(' ');

    return preg_match($pattern, $haystack) === 1;
}

/**
 * Ganti `GenerateInvoiceNumber` di container dengan palsu yang meneruskan ke yang asli, tetapi menjalankan
 * `$onFirstCall` pada panggilan pertama (di dalam transaksi tagihan grup pertama). `calls` menghitung panggilan.
 */
function seleksiFakeInvoiceNumberWithHook(Closure $onFirstCall): object
{
    $real = app(GenerateInvoiceNumber::class);
    $fake = new class($real, $onFirstCall) extends GenerateInvoiceNumber
    {
        public int $calls = 0;

        public function __construct(private GenerateInvoiceNumber $real, private Closure $hook) {}

        public function handle(CarbonInterface $month): string
        {
            if (++$this->calls === 1) {
                ($this->hook)();
            }

            return $this->real->handle($month);
        }
    };

    app()->instance(GenerateInvoiceNumber::class, $fake);

    return $fake;
}

/**
 * Berkas pendaftar langsung lewat factory (tanpa file) untuk syarat wajib jenjangnya (atau hanya yang bernama
 * `$names`), dengan status `$status`. Mengembalikan berkas urut syarat.
 *
 * @param  list<string>|null  $names
 * @return Collection<int, ApplicantDocument>
 */
function seleksiAddDocuments(Applicant $applicant, string $status = 'valid', ?array $names = null): Collection
{
    return $applicant->requirements()->where('is_required', true)
        ->when($names, fn ($query, array $names) => $query->whereIn('name', $names))
        ->orderBy('sort_order')->get()
        ->map(fn (AdmissionRequirement $requirement): ApplicantDocument => pendaftaranDocument($applicant, $requirement, ['status' => $status], false));
}

/**
 * Pendaftar langsung lewat factory di gelombang `$wave` (bawaan W1).
 *
 * @param  array<string, mixed>  $attributes
 */
function seleksiSummaryApplicants(int $count, string $status, array $attributes = [], ?AdmissionWave $wave = null): void
{
    for ($i = 0; $i < $count; $i++) {
        pendaftaranApplicant([
            'status' => $status,
            ...($wave ? ['admission_wave_id' => $wave->id] : []),
            ...$attributes,
        ]);
    }
}

/**
 * Angka stat (teks setelah label stat) pada teks polos widget.
 */
function seleksiStatValue(string $text, string $label): ?int
{
    return preg_match('/'.preg_quote($label, '/').'\s+(\d+)/u', $text, $matches) === 1 ? (int) $matches[1] : null;
}

/*
|--------------------------------------------------------------------------
| Helper repositori dokumen (F013)
|--------------------------------------------------------------------------
|
| Berawalan `dokumen`. Memakai `keuanganUser`, `dashboardUserWith`, `tagihanPlainText`,
| `asramaNotifications`, `pendaftaranRealFile`. Waktu uji bawaan: 2026-10-15 10:00 Asia/Jakarta.
| Dokumen dibuat lewat `dokumenCreate` (forceFill, tanpa factory `Document`), alias morph ditulis
| langsung (`student`, `guardian`, `staff`) agar tidak bergantung pada morph map.
|
*/

/**
 * Fixture: jenjang (UnitSeeder), jenis dokumen (DocumentTypeSeeder), peran, saklar (modul `dokumen` dipaksa aktif),
 * panel admin, waktu dibekukan, disk private/public palsu, santri `s1` (SD, NIS 1001) dan `s2` (SLTP, NIS 2002),
 * wali `g1` (terhubung ke `s1`), staff `t1`, dan user `admin-sekolah` tanpa cakupan (`admin`, user pertama).
 *
 * @return object{sd: Unit, sltp: Unit, slta: Unit, cohortSd: Cohort, cohortSltp: Cohort, s1: Student, s2: Student, g1: Guardian, t1: Staff, admin: User, types: Collection<string, DocumentType>}
 */
function dokumenFixture(): object
{
    test()->seed([UnitSeeder::class, DocumentTypeSeeder::class, RolePermissionSeeder::class, SettingSeeder::class]);
    Setting::set('modules.dokumen.enabled', true);
    Filament::setCurrentPanel('admin');
    test()->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    Storage::fake('private');
    Storage::fake('public');

    $admin = keuanganUser('admin-sekolah');
    $sd = Unit::query()->where('code', 'SD')->firstOrFail();
    $sltp = Unit::query()->where('code', 'SLTP')->firstOrFail();
    $cohortSd = Cohort::factory()->create(['unit_id' => $sd->id, 'entry_year' => 2026]);
    $cohortSltp = Cohort::factory()->create(['unit_id' => $sltp->id, 'entry_year' => 2026]);
    $s1 = Student::factory()->forCohort($cohortSd)->create(['name' => 'Ahmad Satu', 'nis' => '1001']);
    $s2 = Student::factory()->forCohort($cohortSltp)->create(['name' => 'Budi Dua', 'nis' => '2002']);
    $g1 = Guardian::factory()->create(['name' => 'Wali Satu']);
    $s1->guardians()->attach($g1->id, ['relation' => 'ayah', 'is_billing_contact' => false]);

    return (object) [
        'sd' => $sd,
        'sltp' => $sltp,
        'slta' => Unit::query()->where('code', 'SLTA')->firstOrFail(),
        'cohortSd' => $cohortSd,
        'cohortSltp' => $cohortSltp,
        's1' => $s1,
        's2' => $s2,
        'g1' => $g1,
        't1' => Staff::factory()->create(['name' => 'Staff Satu']),
        'admin' => $admin,
        'types' => DocumentType::query()->get()->keyBy('code'),
    ];
}

/**
 * Dokumen langsung lewat forceFill (tanpa Action). Berkas `content` ditulis ke disk `private` (harus sudah di-fake).
 * Bawaan: status `menunggu`, berkas PDF acak di `documents/{alias}/{id}/`, pengunggah = user pertama (dibuat bila belum ada).
 * Kunci khusus `$attributes`: `content` (isi berkas), `valid` (true → status valid, verifikator = pengunggah).
 *
 * @param  array<string, mixed>  $attributes
 */
function dokumenCreate(Model $owner, DocumentType|string $type, array $attributes = []): Document
{
    $type = $type instanceof DocumentType ? $type : DocumentType::query()->where('code', $type)->firstOrFail();
    $alias = match (true) {
        $owner instanceof Student => 'student',
        $owner instanceof Guardian => 'guardian',
        $owner instanceof Staff => 'staff',
    };
    $content = $attributes['content'] ?? 'isi-dokumen';
    $valid = $attributes['valid'] ?? false;
    unset($attributes['content'], $attributes['valid']);

    $path = $attributes['file_path'] ?? "documents/{$alias}/{$owner->getKey()}/".Str::random(24).'.pdf';
    Storage::disk('private')->put($path, $content);
    $uploader = $attributes['uploaded_by'] ?? (User::query()->oldest('id')->value('id') ?? User::factory()->create()->id);

    $document = new Document;
    $document->forceFill([
        'document_type_id' => $type->id,
        'documentable_type' => $alias,
        'documentable_id' => $owner->getKey(),
        'file_path' => $path,
        'original_name' => 'asli-'.Str::lower($type->code).'.pdf',
        'mime_type' => 'application/pdf',
        'size_kb' => 10,
        'status' => $valid ? 'valid' : 'menunggu',
        'verified_by' => $valid ? $uploader : null,
        'verified_at' => $valid ? now() : null,
        'uploaded_by' => $uploader,
        ...$attributes,
    ])->save();

    return $document->fresh();
}

/**
 * Dokumen berstatus `valid`.
 *
 * @param  array<string, mixed>  $attributes
 */
function dokumenValid(Model $owner, DocumentType|string $type, array $attributes = []): Document
{
    return dokumenCreate($owner, $type, ['valid' => true, ...$attributes]);
}

/**
 * Berkas PDF palsu (MIME dilaporkan sesuai argumen).
 */
function dokumenPdf(string $name = 'kk.pdf', int $kb = 300): UploadedFile
{
    return UploadedFile::fake()->create($name, $kb, 'application/pdf');
}

/**
 * Data masukan UploadDocument yang valid untuk jenis `$code` (PDF 300 KB).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dokumenUploadData(object $f, string $code = 'KK', array $overrides = []): array
{
    return [
        'document_type_id' => $f->types[$code]->id,
        'file' => dokumenPdf(),
        ...$overrides,
    ];
}

/**
 * Jenis dokumen langsung lewat factory.
 *
 * @param  array<string, mixed>  $attributes
 */
function dokumenType(array $attributes = []): DocumentType
{
    return DocumentType::factory()->create($attributes);
}

/**
 * Daftar berkas di disk `private`.
 *
 * @return list<string>
 */
function dokumenPrivateFiles(): array
{
    return Storage::disk('private')->allFiles();
}

/**
 * Activity log `dokumen`.
 *
 * @return Collection<int, Activity>
 */
function dokumenActivities(): Collection
{
    return Activity::query()->where('log_name', 'dokumen')->orderBy('id')->get();
}

/**
 * Pola regex untuk pesan `pondok.dokumen.errors.<reason>`; placeholder `:nama` bebas isi.
 */
function dokumenMessagePattern(string $reason): string
{
    $parts = preg_split('/:[A-Za-z_]+/', __("pondok.dokumen.errors.{$reason}"));

    return '/^'.implode('.+', array_map(fn (string $part): string => preg_quote($part, '/'), $parts)).'$/su';
}

/**
 * Pastikan callback melempar ValidationException dengan pesan `pondok.dokumen.errors.<reason>` pada `$field`.
 */
function dokumenAssertRejected(Closure $callback, string $reason, string $field): void
{
    expect(__("pondok.dokumen.errors.{$reason}"))->not->toBe("pondok.dokumen.errors.{$reason}");

    try {
        $callback();
    } catch (ValidationException $exception) {
        $errors = $exception->errors();

        expect($errors)->toHaveKey($field);

        $matches = collect($errors[$field])->contains(fn (string $message): bool => preg_match(dokumenMessagePattern($reason), $message) === 1);

        expect($matches)->toBeTrue("Pesan error tidak sesuai pondok.dokumen.errors.{$reason}: ".json_encode($errors));

        return;
    }

    test()->fail('ValidationException diharapkan, tetapi tidak dilempar.');
}

/**
 * Apakah pesan `pondok.dokumen.errors.<reason>` terlihat di halaman komponen, galat form, atau notifikasi.
 */
function dokumenSeeMessage(Testable $component, string $reason): bool
{
    $message = __("pondok.dokumen.errors.{$reason}");
    expect($message)->not->toBe("pondok.dokumen.errors.{$reason}");

    $pattern = '/'.implode('.+?', array_map(fn (string $part): string => preg_quote($part, '/'), preg_split('/:[A-Za-z_]+/', $message))).'/su';
    $notifications = asramaNotifications()->map(fn (array $n): string => ($n['title'] ?? '').' '.($n['body'] ?? ''))->implode("\n");
    $haystack = tagihanPlainText($component).' '.$notifications.' '.collect($component->errors()->all())->implode(' ');

    return preg_match($pattern, $haystack) === 1;
}

/**
 * Teks notifikasi Filament pada request Livewire terakhir.
 */
function dokumenNotificationText(): string
{
    return asramaNotifications()->map(fn (array $n): string => ($n['title'] ?? '').' '.($n['body'] ?? ''))->implode("\n");
}

/**
 * Teks menu navigasi (hanya `<nav>`) halaman `/admin` sebagai user.
 */
function dokumenNavigationText(User $user): string
{
    $html = test()->actingAs($user)->get('/admin')->assertOk()->getContent();
    preg_match_all('#<nav\b.*?</nav>#is', $html, $navigation);

    expect($navigation[0])->not->toBeEmpty();

    return bayarHtmlText(implode(' ', $navigation[0]));
}

/**
 * Banyaknya query yang dijalankan callback.
 */
function dokumenQueryCount(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/**
 * Jenis dokumen santri wajib + berlaku sementara (untuk uji kedaluwarsa kelengkapan).
 */
function dokumenRequiredExpiringType(string $code = 'KIP'): DocumentType
{
    return dokumenType(['code' => $code, 'name' => 'Kartu Indonesia Pintar', 'owner' => 'santri', 'is_required' => true, 'has_expiry' => true]);
}

/*
|--------------------------------------------------------------------------
| Helper konten / CMS (F016)
|--------------------------------------------------------------------------
|
| Berawalan `konten`. Model Post/Album/Photo/Video/Category dibuat lewat forceFill (tanpa factory)
| agar test tidak bergantung pada factory yang dibuat implementer. Waktu uji bawaan:
| 2026-10-15 10:00 Asia/Jakarta. Disk `public` dan `private` dipalsukan.
|
*/

/**
 * Seed jenjang, peran, saklar (modul `konten` aktif lewat ENABLED_BY_DEFAULT), panel admin, waktu, dan disk palsu.
 * Dengan `$withPages` ikut `StaticPageSeeder` (tiga halaman statis).
 *
 * @return object{sd: Unit, sltp: Unit}
 */
function kontenBoot(bool $withPages = false): object
{
    test()->seed([UnitSeeder::class, RolePermissionSeeder::class, SettingSeeder::class]);

    if ($withPages) {
        test()->seed(StaticPageSeeder::class);
    }

    Filament::setCurrentPanel('admin');
    test()->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    Storage::fake('public');
    Storage::fake('private');

    return (object) [
        'sd' => Unit::query()->where('code', 'SD')->firstOrFail(),
        'sltp' => Unit::query()->where('code', 'SLTP')->firstOrFail(),
    ];
}

/**
 * @param  list<string>  $unitCodes
 */
function kontenUser(string $role, array $unitCodes = []): User
{
    return keuanganUser($role, $unitCodes);
}

/**
 * Pola regex untuk pesan `pondok.konten.errors.<reason>`; placeholder `:nama` bebas isi.
 */
function kontenMessagePattern(string $reason): string
{
    $parts = preg_split('/:[A-Za-z_]+/', __("pondok.konten.errors.{$reason}"));

    return '/^'.implode('.+', array_map(fn (string $part): string => preg_quote($part, '/'), $parts)).'$/su';
}

/**
 * Pastikan callback melempar ValidationException dengan pesan `pondok.konten.errors.<reason>`.
 * Bila `$field` diisi, error harus ada pada field tersebut.
 */
function kontenAssertRejected(Closure $callback, string $reason, ?string $field = null): void
{
    expect(__("pondok.konten.errors.{$reason}"))->not->toBe("pondok.konten.errors.{$reason}");

    try {
        $callback();
    } catch (ValidationException $exception) {
        $errors = $exception->errors();

        if ($field !== null) {
            expect($errors)->toHaveKey($field);
        }

        $matches = collect($errors)->flatten()->contains(fn (string $message): bool => preg_match(kontenMessagePattern($reason), $message) === 1);

        expect($matches)->toBeTrue("Pesan error tidak sesuai pondok.konten.errors.{$reason}: ".json_encode($errors));

        return;
    }

    test()->fail('ValidationException diharapkan, tetapi tidak dilempar.');
}

/**
 * Apakah pesan `pondok.konten.errors.<reason>` terlihat di halaman komponen, galat form, atau notifikasi.
 */
function kontenSeeMessage(Testable $component, string $reason): bool
{
    $message = __("pondok.konten.errors.{$reason}");
    expect($message)->not->toBe("pondok.konten.errors.{$reason}");

    $pattern = '/'.implode('.+?', array_map(fn (string $part): string => preg_quote($part, '/'), preg_split('/:[A-Za-z_]+/', $message))).'/su';
    $notifications = asramaNotifications()->map(fn (array $n): string => ($n['title'] ?? '').' '.($n['body'] ?? ''))->implode("\n");
    $haystack = tagihanPlainText($component).' '.$notifications.' '.collect($component->errors()->all())->implode(' ');

    return preg_match($pattern, $haystack) === 1;
}

/**
 * Gambar uji (JPEG sungguhan dibuat GD, ukuran yang dilaporkan `$kb` KB).
 */
function kontenImage(string $name = 'a.jpg', int $kb = 500): UploadedFile
{
    return UploadedFile::fake()->image($name, 800, 600)->size($kb);
}

/**
 * Berkas PDF uji (MIME dilaporkan application/pdf).
 */
function kontenPdf(string $name = 'a.pdf', int $kb = 100): UploadedFile
{
    return UploadedFile::fake()->create($name, $kb, 'application/pdf');
}

/**
 * Masukan `SavePost` yang valid; `$overrides` menimpa.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function kontenPostData(array $overrides = []): array
{
    return [
        'title' => 'Wisuda Tahfidz 2026',
        'body' => '<p>Alhamdulillah</p>',
        'status' => 'draft',
        ...$overrides,
    ];
}

/**
 * Post langsung lewat forceFill (tanpa Action). Bawaan: berita draft dengan judul unik.
 *
 * @param  array<string, mixed>  $attributes
 */
function kontenPost(array $attributes = []): Post
{
    static $counter = 0;
    $counter++;

    $title = $attributes['title'] ?? "Berita uji {$counter}";
    $post = new Post;
    $post->forceFill([
        'title' => $title,
        'slug' => Str::slug($title),
        'body' => '<p>Isi berita</p>',
        'status' => 'draft',
        'published_at' => null,
        ...$attributes,
    ])->save();

    return $post;
}

/**
 * Kategori langsung lewat forceFill.
 */
function kontenCategory(string $type = 'album', string $name = 'Fasilitas', ?string $slug = null): Category
{
    $category = new Category;
    $category->forceFill(['type' => $type, 'name' => $name, 'slug' => $slug ?? Str::slug($name)])->save();

    return $category;
}

/**
 * Album langsung lewat forceFill (kategori album dibuat bila `category_id` tidak diberikan).
 *
 * @param  array<string, mixed>  $attributes
 */
function kontenAlbum(array $attributes = []): Album
{
    static $counter = 0;
    $counter++;

    $title = $attributes['title'] ?? "Album uji {$counter}";
    $categoryId = $attributes['category_id'] ?? (Category::query()->where('type', 'album')->value('id') ?? kontenCategory()->id);
    $album = new Album;
    $album->forceFill(['title' => $title, 'slug' => Str::slug($title), 'description' => null, ...$attributes, 'category_id' => $categoryId])->save();

    return $album;
}

/**
 * Foto langsung lewat forceFill; berkasnya ditulis ke disk `public` (harus sudah dipalsukan).
 *
 * @param  array<string, mixed>  $attributes
 */
function kontenPhoto(Album $album, array $attributes = []): Photo
{
    $path = $attributes['path'] ?? "albums/{$album->id}/".Str::uuid().'.jpg';
    Storage::disk('public')->put($path, 'gambar');

    $photo = new Photo;
    $photo->forceFill(['album_id' => $album->id, 'caption' => null, 'sort_order' => 0, ...$attributes, 'path' => $path])->save();

    return $photo;
}

/**
 * Video langsung lewat forceFill.
 *
 * @param  array<string, mixed>  $attributes
 */
function kontenVideo(array $attributes = []): Video
{
    $video = new Video;
    $video->forceFill([
        'title' => 'Video uji',
        'youtube_id' => 'dQw4w9WgXcQ',
        'description' => null,
        'is_featured' => false,
        'sort_order' => 0,
        ...$attributes,
    ])->save();

    return $video;
}

/**
 * Daftar berkas pada disk (bawaan `public`).
 *
 * @return list<string>
 */
function kontenFiles(string $disk = 'public'): array
{
    return Storage::disk($disk)->allFiles();
}

/**
 * Activity log `konten`.
 *
 * @return Collection<int, Activity>
 */
function kontenActivities(): Collection
{
    return Activity::query()->where('log_name', 'konten')->orderBy('id')->get();
}

/**
 * Teks menu navigasi (hanya `<nav>`) halaman `/admin` sebagai user.
 */
function kontenNavigationText(User $user): string
{
    $html = test()->actingAs($user)->get('/admin')->assertOk()->getContent();
    preg_match_all('#<nav\b.*?</nav>#is', $html, $navigation);

    expect($navigation[0])->not->toBeEmpty();

    return bayarHtmlText(implode(' ', $navigation[0]));
}

/**
 * Teks polos respons HTTP halaman admin sebagai user (status 200 diwajibkan).
 */
function kontenPageText(User $user, string $url): string
{
    return bayarHtmlText(test()->actingAs($user)->get($url)->assertOk()->getContent());
}

/**
 * Body Arab: `<p>` + `$letters` huruf "ب" (2 byte per huruf) + `</p>`.
 */
function kontenArabicBody(int $letters): string
{
    return '<p>'.str_repeat('ب', $letters).'</p>';
}

/*
|--------------------------------------------------------------------------
| Helper surat keluar (F014)
|--------------------------------------------------------------------------
|
| Berawalan `surat`. Waktu uji bawaan: 2026-10-15 10:00 Asia/Jakarta. Cakupan jenjang `IssueLetter`
| mengikuti user LOGIN (bukan `$issuer`), sehingga `suratIssue` selalu `actingAs` penerbit lalu
| memulihkan status login sebelumnya. Baris mentah (constraint DB) lewat `suratRow` + `DB::table`.
| Fake `LetterNumbering`/`IssueLetter` berupa subkelas anonim (pola `pendaftaranFake*`) yang
| menyalin properti instance asli dan didaftarkan lewat `app()->instance()`.
|
*/

/**
 * Fixture: seeder (jenjang, peran, settings), modul `dokumen` aktif, `school.name` "Ma'had Al-Kitab", panel admin,
 * waktu dibekukan, disk palsu, tahun ajaran aktif 2026/2027, santri `s1` (SD, aktif, rombel 4A, NIS 2026000123,
 * "Muhammad Fauzan Akbar") dan `s2` (SLTP, aktif, rombel 7A), staff `t1` (aktif, Kepala Sekolah, NIP 1987) dan `t2`
 * (nonaktif), user admin-sekolah tanpa cakupan `admin` dan bercakupan SLTP `scoped`.
 *
 * @return object{sd: Unit, sltp: Unit, slta: Unit, year: AcademicYear, classSd: Classroom, classSltp: Classroom, cohortSd: Cohort, cohortSltp: Cohort, s1: Student, s2: Student, t1: Staff, t2: Staff, admin: User, scoped: User}
 */
function suratFixture(): object
{
    test()->seed([UnitSeeder::class, RolePermissionSeeder::class, SettingSeeder::class]);
    Setting::set('modules.dokumen.enabled', true);
    Setting::set('school.name', "Ma'had Al-Kitab");
    Filament::setCurrentPanel('admin');
    test()->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    Storage::fake('public');
    Storage::fake('private');

    $sd = Unit::query()->where('code', 'SD')->firstOrFail();
    $sltp = Unit::query()->where('code', 'SLTP')->firstOrFail();
    $year = AcademicYear::factory()->active()->create(['name' => '2026/2027', 'starts_on' => '2026-07-15', 'ends_on' => '2027-06-30']);
    $classSd = Classroom::factory()->create(['academic_year_id' => $year->id, 'unit_id' => $sd->id, 'grade' => 4, 'name' => '4A']);
    $classSltp = Classroom::factory()->create(['academic_year_id' => $year->id, 'unit_id' => $sltp->id, 'grade' => 7, 'name' => '7A']);
    $cohortSd = Cohort::factory()->create(['unit_id' => $sd->id, 'entry_year' => 2026]);
    $cohortSltp = Cohort::factory()->create(['unit_id' => $sltp->id, 'entry_year' => 2026]);
    $s1 = Student::factory()->forCohort($cohortSd)->create([
        'name' => 'Muhammad Fauzan Akbar', 'nis' => '2026000123', 'nisn' => '0098765432', 'gender' => 'L',
        'birth_place' => 'Bandung', 'birth_date' => '2014-03-02',
    ]);
    $s2 = Student::factory()->forCohort($cohortSltp)->create([
        'name' => 'Budi Santoso', 'nis' => '2026000456', 'nisn' => '0011223344', 'gender' => 'L',
        'birth_place' => 'Garut', 'birth_date' => '2012-08-17',
    ]);
    Enrollment::factory()->create(['student_id' => $s1->id, 'classroom_id' => $classSd->id, 'academic_year_id' => $year->id]);
    Enrollment::factory()->create(['student_id' => $s2->id, 'classroom_id' => $classSltp->id, 'academic_year_id' => $year->id]);

    return (object) [
        'sd' => $sd,
        'sltp' => $sltp,
        'slta' => Unit::query()->where('code', 'SLTA')->firstOrFail(),
        'year' => $year,
        'classSd' => $classSd,
        'classSltp' => $classSltp,
        'cohortSd' => $cohortSd,
        'cohortSltp' => $cohortSltp,
        's1' => $s1,
        's2' => $s2,
        't1' => Staff::factory()->create(['name' => 'Ust. Hasan', 'position' => 'Kepala Sekolah', 'nip' => '1987', 'is_active' => true]),
        't2' => Staff::factory()->create(['name' => 'Ust. Nonaktif', 'position' => 'Guru', 'nip' => '2000', 'is_active' => false]),
        'admin' => keuanganUser('admin-sekolah'),
        'scoped' => keuanganUser('admin-sekolah', ['SLTP']),
    ];
}

/**
 * @param  list<string>  $unitCodes
 */
function suratUser(string $role, array $unitCodes = []): User
{
    return keuanganUser($role, $unitCodes);
}

/**
 * Santri tambahan (aktif, rombel aktif bila `$enrolled`), jenjang SD atau SLTP.
 *
 * @param  array<string, mixed>  $attributes
 */
function suratStudent(object $f, string $unit = 'SD', array $attributes = [], bool $enrolled = true): Student
{
    $cohort = $unit === 'SD' ? $f->cohortSd : $f->cohortSltp;
    $student = Student::factory()->forCohort($cohort)->create($attributes);

    if ($enrolled) {
        Enrollment::factory()->create([
            'student_id' => $student->id,
            'classroom_id' => $unit === 'SD' ? $f->classSd->id : $f->classSltp->id,
            'academic_year_id' => $f->year->id,
        ]);
    }

    return $student;
}

/**
 * Staff tambahan.
 *
 * @param  array<string, mixed>  $attributes
 */
function suratSigner(array $attributes = []): Staff
{
    return Staff::factory()->create(['is_active' => true, ...$attributes]);
}

/**
 * Terbitkan surat lewat Action sebagai `$by` (bawaan admin). Bawaan: `keterangan_aktif`, santri `s1`, penandatangan
 * `t1` (override `signers` satu slot), keperluan "Persyaratan beasiswa". Status login sebelumnya dipulihkan.
 *
 * @param  array<string, mixed>  $overrides
 */
function suratIssue(object $f, array $overrides = [], ?Student $student = null, ?User $by = null): IssuedLetter
{
    $previous = Auth::user();
    $by ??= $f->admin;
    test()->actingAs($by);

    try {
        return app(IssueLetter::class)->handle($student ?? $f->s1, [
            'type' => 'keterangan_aktif',
            'signers' => [$f->t1->id],
            'purpose' => 'Persyaratan beasiswa',
            ...$overrides,
        ], $by);
    } finally {
        if ($previous === null) {
            Auth::forgetGuards();
        } else {
            test()->actingAs($previous);
        }
    }
}

/**
 * Kode verifikasi acak 12 karakter dari alfabet resmi.
 */
function suratCode(): string
{
    return substr(str_shuffle(str_repeat('23456789ABCDEFGHJKMNPQRSTUVWXYZ', 2)), 0, 12);
}

/**
 * Baris mentah `issued_letters` (tanpa kolom `status`, agar bawaan DB teruji). Nomor dan urutan unik per pemanggilan.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function suratRow(object $f, array $overrides = []): array
{
    static $counter = 0;
    $counter++;

    return [
        'letter_no' => sprintf('%03d/RAW/SKA/X/2026', 500 + $counter),
        'type' => 'keterangan_aktif',
        'sequence_year' => 2026,
        'sequence' => 500 + $counter,
        'student_id' => $f->s1->id,
        'signed_by_staff_id' => $f->t1->id,
        'issued_on' => '2026-10-15',
        'purpose' => 'Uji',
        'verification_code' => suratCode(),
        'snapshot' => json_encode(['student' => ['name' => 'Uji'], 'signer' => ['name' => 'Uji'], 'school' => ['name' => 'Uji']]),
        'issued_by' => $f->admin->id,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

/**
 * Masukan UpdateLetterNumbering yang valid (bawaan); `type_codes` parsial digabung dengan kode bawaan.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function suratNumberingData(array $overrides = []): array
{
    $typeCodes = ['keterangan_aktif' => 'SKA', 'pindah' => 'SKP', 'rekomendasi' => 'SRK', 'syahadah' => 'SYH', 'kartu_santri' => 'KTS'];

    return [
        'number_format' => '{seq}/{school_code}/{type_code}/{month_roman}/{year}',
        'school_code' => null,
        'sequence_padding' => 3,
        ...$overrides,
        'type_codes' => [...$typeCodes, ...($overrides['type_codes'] ?? [])],
    ];
}

/**
 * Pastikan callback melempar ValidationException `pondok.dokumen.errors.<reason>` pada `$field`.
 */
function suratAssertRejected(Closure $callback, string $reason, string $field): void
{
    dokumenAssertRejected($callback, $reason, $field);
}

/**
 * Pengecualian deadlock MySQL (1213 / SQLSTATE 40001) yang dibungkus QueryException.
 */
function suratDeadlock(): QueryException
{
    return new QueryException('sqlite', 'select max(sequence) from issued_letters', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
}

/**
 * QueryException biasa (tabel tidak ada, SQLSTATE 42S02), bukan konflik konkurensi.
 */
function suratPlainQueryException(): QueryException
{
    return new QueryException('sqlite', 'select max(sequence) from issued_letters', [], new PDOException('SQLSTATE[42S02]: Base table or view not found: 1146 Table issued_letters doesn\'t exist'));
}

/**
 * Ganti `LetterNumbering` di container: `nextSequence` memakai `$script` berurutan (int dikembalikan, Throwable dilempar),
 * lalu `$always` (bila ada) atau implementasi asli. Properti `calls` mencatat jumlah panggilan.
 *
 * @param  list<int|Throwable>  $script
 */
function suratFakeNumbering(array $script = [], ?int $always = null): LetterNumbering
{
    $real = app(LetterNumbering::class);
    $fake = new class($script, $always) extends LetterNumbering
    {
        public int $calls = 0;

        /** @param list<int|Throwable> $script */
        public function __construct(private array $script, private ?int $always) {}

        public function nextSequence(string $type, int $year): int
        {
            $this->calls++;
            $next = array_shift($this->script) ?? $this->always;

            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next ?? parent::nextSequence($type, $year);
        }
    };

    suratCopyProperties(LetterNumbering::class, $real, $fake);
    app()->instance(LetterNumbering::class, $fake);

    return $fake;
}

/**
 * Ganti `IssueLetter` di container: `verificationCode()` mengembalikan `$codes` berurutan, lalu kode asli.
 *
 * @param  list<string>  $codes
 */
function suratFakeIssuer(array $codes): IssueLetter
{
    $real = app(IssueLetter::class);
    $fake = new class($codes) extends IssueLetter
    {
        public int $calls = 0;

        /** @param list<string> $codes */
        public function __construct(private array $codes) {}

        public function verificationCode(): string
        {
            $this->calls++;

            return array_shift($this->codes) ?? parent::verificationCode();
        }
    };

    suratCopyProperties(IssueLetter::class, $real, $fake);
    app()->instance(IssueLetter::class, $fake);

    return $fake;
}

/**
 * Salin properti instance kelas induk dari objek asli ke subkelas anonim (konstruktor induk tidak dipanggil).
 *
 * @param  class-string  $class
 */
function suratCopyProperties(string $class, object $real, object $fake): void
{
    foreach ((new ReflectionClass($class))->getProperties() as $property) {
        if (! $property->isStatic() && $property->isInitialized($real)) {
            $property->setValue($fake, $property->getValue($real));
        }
    }
}

/**
 * Teks polos HTML (tag, script, dan style dibuang).
 */
function suratText(string $html): string
{
    return bayarHtmlText($html);
}

/**
 * Notifikasi Filament pada request Livewire terakhir, `status|judul isi` per baris.
 */
function suratNotifications(): string
{
    return asramaNotifications()->map(fn (array $n): string => ($n['status'] ?? '').'|'.($n['title'] ?? '').' '.($n['body'] ?? ''))->implode("\n");
}

/**
 * Header privasi halaman verifikasi publik.
 */
function suratAssertPrivacyHeaders(TestResponse $response): void
{
    expect($response->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
}

/*
|--------------------------------------------------------------------------
| Helper modul Prestasi (F015)
|--------------------------------------------------------------------------
|
| Berawalan `prestasi`. Prestasi dan pesertanya dibuat langsung (forceFill + DB::table, tanpa factory)
| agar test tidak bergantung pada factory yang dibuat implementer. Memakai `keuanganUser`,
| `tagihanPlainText`, `asramaNotifications`. Waktu uji bawaan: 2026-10-15 10:00 Asia/Jakarta.
|
*/

/**
 * Fixture: jenjang, peran, saklar (prestasi, konten, dokumen aktif), jenis dokumen, panel admin, waktu dibekukan,
 * disk palsu, tahun ajaran 2025/2026 (`y1`) dan 2026/2027 aktif (`y2`), santri `s1` (SD, "Ahmad Satu"),
 * `s2` (SLTP, "Budi Dua"), `s3` (SLTP, "Candra Tiga"), serta user `k` (kesantrian), `a` (admin-sekolah),
 * `c` (admin-konten), `k2` (kesantrian SLTP), `a2` (admin-sekolah SLTP), `admin` (super-admin).
 *
 * @return object{sd: Unit, sltp: Unit, slta: Unit, y1: AcademicYear, y2: AcademicYear, s1: Student, s2: Student, s3: Student, k: User, a: User, c: User, k2: User, a2: User, admin: User, types: Collection<string, DocumentType>}
 */
function prestasiFixture(): object
{
    test()->seed([UnitSeeder::class, RolePermissionSeeder::class, SettingSeeder::class, DocumentTypeSeeder::class]);

    foreach (['prestasi', 'konten', 'dokumen'] as $module) {
        Setting::set("modules.{$module}.enabled", true);
    }

    Filament::setCurrentPanel('admin');
    test()->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    Storage::fake('private');
    Storage::fake('public');

    $sd = Unit::query()->where('code', 'SD')->firstOrFail();
    $sltp = Unit::query()->where('code', 'SLTP')->firstOrFail();
    $y1 = AcademicYear::factory()->create(['name' => '2025/2026', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
    $y2 = AcademicYear::factory()->active()->create(['name' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
    $cohortSd = Cohort::factory()->create(['unit_id' => $sd->id, 'entry_year' => 2026]);
    $cohortSltp = Cohort::factory()->create(['unit_id' => $sltp->id, 'entry_year' => 2026]);

    return (object) [
        'sd' => $sd,
        'sltp' => $sltp,
        'slta' => Unit::query()->where('code', 'SLTA')->firstOrFail(),
        'y1' => $y1,
        'y2' => $y2,
        'cohortSd' => $cohortSd,
        'cohortSltp' => $cohortSltp,
        's1' => Student::factory()->forCohort($cohortSd)->create(['name' => 'Ahmad Satu', 'nis' => '1001']),
        's2' => Student::factory()->forCohort($cohortSltp)->create(['name' => 'Budi Dua', 'nis' => '2002']),
        's3' => Student::factory()->forCohort($cohortSltp)->create(['name' => 'Candra Tiga', 'nis' => '3003']),
        'k' => keuanganUser('kesantrian'),
        'a' => keuanganUser('admin-sekolah'),
        'c' => keuanganUser('admin-konten'),
        'k2' => keuanganUser('kesantrian', ['SLTP']),
        'a2' => keuanganUser('admin-sekolah', ['SLTP']),
        'admin' => keuanganUser('super-admin'),
        'types' => DocumentType::query()->get()->keyBy('code'),
    ];
}

/**
 * Santri tambahan di jenjang `$unit` ('SD' atau 'SLTP').
 *
 * @param  array<string, mixed>  $attributes
 */
function prestasiStudent(object $f, string $unit = 'SLTP', array $attributes = []): Student
{
    return Student::factory()->forCohort($unit === 'SD' ? $f->cohortSd : $f->cohortSltp)->create($attributes);
}

/**
 * Prestasi langsung lewat forceFill (tanpa Action). `$participants` berisi Student atau `[Student, rank, role]`.
 * Kunci khusus `$attributes`: `verified` (true -> `verified_by` = `a`, bawaan belum diverifikasi), `public` (is_public).
 * Bawaan: tahfidz, kabupaten, `kind` mengikuti jumlah peserta, 2026-08-17, tahun 2026/2027, dicatat `k`, peringkat "Juara 1".
 *
 * @param  list<Student|array{0: Student, 1?: string, 2?: ?string}>  $participants
 * @param  array<string, mixed>  $attributes
 */
function prestasiCreate(object $f, array $participants, array $attributes = []): Achievement
{
    static $counter = 0;
    $counter++;

    $verified = $attributes['verified'] ?? false;
    $public = $attributes['public'] ?? false;
    unset($attributes['verified'], $attributes['public']);

    $achievement = new Achievement;
    $achievement->forceFill([
        'academic_year_id' => $f->y2->id,
        'category' => 'tahfidz',
        'title' => "Prestasi uji {$counter}",
        'event_name' => 'MTQ Tingkat Kabupaten 2026',
        'organizer' => null,
        'level' => 'kabupaten',
        'kind' => count($participants) > 1 ? 'beregu' : 'individu',
        'achieved_on' => '2026-08-17',
        'recorded_by' => $f->k->id,
        'verified_by' => $verified ? $f->a->id : null,
        'is_public' => $public,
        ...$attributes,
    ])->save();

    foreach ($participants as $participant) {
        [$student, $rank, $role] = is_array($participant) ? [$participant[0], $participant[1] ?? 'Juara 1', $participant[2] ?? null] : [$participant, 'Juara 1', null];

        DB::table('achievement_student')->insert([
            'achievement_id' => $achievement->id,
            'student_id' => $student->id,
            'rank' => $rank,
            'role' => $role,
        ]);
    }

    return Achievement::withoutUnitScope()->findOrFail($achievement->id);
}

/**
 * Satu baris `achievements` lewat query builder (tanpa model, tanpa peserta), untuk test yang hanya butuh FK.
 * Membuat tahun ajaran dan user pencatat bila belum ada.
 */
function prestasiRawAchievement(?int $recordedBy = null): int
{
    static $counter = 0;
    $counter++;

    $year = AcademicYear::query()->first() ?? AcademicYear::factory()->create();

    return DB::table('achievements')->insertGetId([
        'academic_year_id' => $year->id,
        'category' => 'tahfidz',
        'title' => "Prestasi mentah {$counter}",
        'event_name' => 'Lomba',
        'level' => 'pondok',
        'kind' => 'individu',
        'achieved_on' => '2026-08-17',
        'recorded_by' => $recordedBy ?? User::query()->oldest('id')->value('id') ?? User::factory()->create()->id,
        'is_public' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Masukan SaveAchievement yang valid (individu, peserta `s2`); `$overrides` menimpa.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function prestasiData(object $f, array $overrides = []): array
{
    return [
        'category' => 'tahfidz',
        'title' => 'Juara 1 MTQ Tahfidz 10 Juz',
        'event_name' => 'MTQ Tingkat Kabupaten 2026',
        'level' => 'kabupaten',
        'kind' => 'individu',
        'achieved_on' => '2026-08-17',
        'participants' => [['student_id' => $f->s2->id, 'rank' => 'Juara 1']],
        ...$overrides,
    ];
}

/**
 * Jalankan SaveAchievement (user login diatur test pemanggil lewat `actingAs`).
 *
 * @param  array<string, mixed>  $data
 */
function prestasiSave(array $data, User $actor, ?Achievement $achievement = null): Achievement
{
    return app(SaveAchievement::class)->handle($data, $actor, $achievement);
}

/**
 * Pola regex untuk pesan `pondok.prestasi.errors.<reason>`; placeholder `:nama` bebas isi.
 */
function prestasiMessagePattern(string $reason): string
{
    $parts = preg_split('/:[A-Za-z_]+/', __("pondok.prestasi.errors.{$reason}"));

    return '/^'.implode('.+', array_map(fn (string $part): string => preg_quote($part, '/'), $parts)).'$/su';
}

/**
 * Pastikan callback melempar ValidationException dengan pesan `pondok.prestasi.errors.<reason>`.
 * Bila `$field` diisi, error harus ada pada field tersebut.
 */
function prestasiAssertRejected(Closure $callback, string $reason, ?string $field = null): void
{
    expect(__("pondok.prestasi.errors.{$reason}"))->not->toBe("pondok.prestasi.errors.{$reason}");

    try {
        $callback();
    } catch (ValidationException $exception) {
        $errors = $exception->errors();

        if ($field !== null) {
            expect($errors)->toHaveKey($field);
        }

        $matches = collect($errors)->flatten()->contains(fn (string $message): bool => preg_match(prestasiMessagePattern($reason), $message) === 1);

        expect($matches)->toBeTrue("Pesan error tidak sesuai pondok.prestasi.errors.{$reason}: ".json_encode($errors));

        return;
    }

    test()->fail('ValidationException diharapkan, tetapi tidak dilempar.');
}

/**
 * Apakah pesan `pondok.prestasi.errors.<reason>` terlihat di halaman komponen, galat form, atau notifikasi.
 */
function prestasiSeeMessage(Testable $component, string $reason): bool
{
    $message = __("pondok.prestasi.errors.{$reason}");
    expect($message)->not->toBe("pondok.prestasi.errors.{$reason}");

    $pattern = '/'.implode('.+?', array_map(fn (string $part): string => preg_quote($part, '/'), preg_split('/:[A-Za-z_]+/', $message))).'/su';
    $notifications = asramaNotifications()->map(fn (array $n): string => ($n['title'] ?? '').' '.($n['body'] ?? ''))->implode("\n");
    $haystack = tagihanPlainText($component).' '.$notifications.' '.collect($component->errors()->all())->implode(' ');

    return preg_match($pattern, $haystack) === 1;
}

/**
 * Dokumen milik prestasi langsung lewat forceFill (berkas ditulis ke disk `private`, harus sudah di-fake).
 *
 * @param  array<string, mixed>  $attributes
 */
function prestasiDocument(object $f, Achievement $achievement, string $code = 'SERTIFIKAT', array $attributes = []): Document
{
    $path = "documents/achievement/{$achievement->id}/".Str::random(24).'.pdf';
    Storage::disk('private')->put($path, 'isi-sertifikat');

    $document = new Document;
    $document->forceFill([
        'document_type_id' => $f->types[$code]->id,
        'documentable_type' => 'achievement',
        'documentable_id' => $achievement->id,
        'file_path' => $path,
        'original_name' => 'sertifikat.pdf',
        'mime_type' => 'application/pdf',
        'size_kb' => 10,
        'status' => 'menunggu',
        'uploaded_by' => $f->k->id,
        ...$attributes,
    ])->save();

    return Document::withoutUnitScope()->findOrFail($document->id);
}

/**
 * Banyaknya query yang dijalankan callback.
 */
function prestasiQueryCount(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/**
 * Notifikasi Filament pada request Livewire terakhir sebagai teks.
 */
function prestasiNotificationText(): string
{
    return asramaNotifications()->map(fn (array $n): string => ($n['title'] ?? '').' '.($n['body'] ?? ''))->implode("\n");
}

/**
 * Teks menu navigasi (hanya `<nav>`) halaman `/admin` sebagai user.
 */
function prestasiNavigationText(User $user): string
{
    $html = test()->actingAs($user)->get('/admin')->assertOk()->getContent();
    preg_match_all('#<nav\b.*?</nav>#is', $html, $navigation);

    expect($navigation[0])->not->toBeEmpty();

    return bayarHtmlText(implode(' ', $navigation[0]));
}

/**
 * Peserta pivot sebagai `[student_id => [rank, role]]`.
 *
 * @return array<int, array{0: string, 1: ?string}>
 */
function prestasiPivot(Achievement $achievement): array
{
    return DB::table('achievement_student')->where('achievement_id', $achievement->id)->orderBy('student_id')->get()
        ->mapWithKeys(fn (object $row): array => [$row->student_id => [$row->rank, $row->role]])->all();
}

/**
 * Data AC-19: 2026/2027 P1 (tahfidz, kabupaten, S2), P2 (tahfidz, pondok, S2+S3), P3 (olahraga, provinsi, S1),
 * PU (akademik, nasional, S3, belum diverifikasi); 2025/2026 P4 (seni, nasional, S2). Selain PU semuanya terverifikasi.
 *
 * @return object{p1: Achievement, p2: Achievement, p3: Achievement, pu: Achievement, p4: Achievement}
 */
function prestasiRecapData(object $f): object
{
    return (object) [
        'p1' => prestasiCreate($f, [$f->s2], ['verified' => true, 'category' => 'tahfidz', 'level' => 'kabupaten']),
        'p2' => prestasiCreate($f, [$f->s2, $f->s3], ['verified' => true, 'category' => 'tahfidz', 'level' => 'pondok']),
        'p3' => prestasiCreate($f, [$f->s1], ['verified' => true, 'category' => 'olahraga', 'level' => 'provinsi']),
        'pu' => prestasiCreate($f, [$f->s3], ['category' => 'akademik', 'level' => 'nasional']),
        'p4' => prestasiCreate($f, [$f->s2], ['verified' => true, 'category' => 'seni', 'level' => 'nasional', 'academic_year_id' => $f->y1->id, 'achieved_on' => '2026-03-10']),
    ];
}

/*
|--------------------------------------------------------------------------
| Helper daftar ulang PSB (F012)
|--------------------------------------------------------------------------
|
| Berawalan `daftarUlang`. Dibangun di atas helper F010/F011 yang nyata (`pendaftaranFixture`, `seleksiExtend`,
| `seleksiApplicant`, `seleksiRun`, `seleksiInvoices`), bukan nama `penerimaan*` yang disebut rencana.
| Waktu uji bawaan: 2026-10-15 10:00 Asia/Jakarta. Tarif di atas fixture F011 disesuaikan ke angka rencana:
| UANG_PANGKAL 6.000.000 dan SERAGAM 2.000.000 (grup PSB_TERIMA, total penerimaan 8.000.000), SPP 500.000, KITAB tanpa tarif.
|
*/

/**
 * Sesuaikan fixture F011 (`seleksiExtend`) ke angka rencana F012. Tidak mengubah waktu.
 *
 * @return object{uangPangkal: FeeType, seragam: FeeType, spp: FeeType, rates: array<string, FeeRate>, user: User}
 */
function daftarUlangExtend(object $f): object
{
    $f = seleksiExtend($f);
    $f->rates['UANG_PANGKAL']->update(['amount' => 6000000]);
    $f->rates['SERAGAM']->update(['amount' => 2000000]);
    $f->rates['SPP']->update(['amount' => 500000]);
    $f->rates['KITAB']->delete();
    unset($f->rates['KITAB']);
    Unit::query()->update(['min_reregistration_amount' => 4000000]);

    return $f;
}

/**
 * Atur nominal minimal daftar ulang jenjang `$unitCode` (F040): `null` wajib lunas, `0` tanpa syarat.
 */
function daftarUlangMinimum(string $unitCode, ?int $amount): void
{
    Unit::query()->where('code', $unitCode)->update(['min_reregistration_amount' => $amount]);
}

/**
 * Fixture F012: `pendaftaranFixture()` + `daftarUlangExtend()`, waktu 2026-10-15 10:00 Asia/Jakarta.
 */
function daftarUlangFixture(): object
{
    return daftarUlangExtend(pendaftaranFixture());
}

/**
 * Berkas pendaftar dengan file nyata di disk `private` (isi unik), status `$status`.
 */
function daftarUlangDocument(Applicant $applicant, AdmissionRequirement $requirement, string $status = 'valid', bool $withFile = true): ApplicantDocument
{
    $verifier = User::query()->oldest('id')->value('id') ?? User::factory()->create()->id;
    $code = $requirement->documentType->code;
    $document = pendaftaranDocument($applicant, $requirement, [
        'status' => $status,
        'original_name' => Str::lower($code).'-asli.pdf',
        'size_kb' => 7,
        'verified_by' => $status === 'menunggu' ? null : $verifier,
        'verified_at' => $status === 'menunggu' ? null : now(),
    ], $withFile);

    if ($withFile) {
        Storage::disk('private')->put($document->file_path, "%PDF-1.4 isi {$code} pendaftar {$applicant->id}");
    }

    return $document;
}

/**
 * Berkas pendaftar untuk syarat berjenis dokumen `$code` (syarat harus sudah ada untuk jenjang pendaftar).
 */
function daftarUlangValidDocument(Applicant $applicant, string $code, string $status = 'valid', bool $withFile = true): ApplicantDocument
{
    $requirement = AdmissionRequirement::query()->forUnit($applicant->unit_id)
        ->whereHas('documentType', fn ($query) => $query->where('code', $code))->firstOrFail();

    return daftarUlangDocument($applicant, $requirement, $status, $withFile);
}

/**
 * Syarat berkas semua jenjang untuk jenis dokumen baru `$code` dengan pemilik `$owner` (`santri`/`wali`/`staff`).
 */
function daftarUlangRequirement(string $code, string $owner, bool $required = false): AdmissionRequirement
{
    $type = DocumentType::factory()->create(['code' => $code, 'name' => "Jenis {$code}", 'owner' => $owner]);

    return AdmissionRequirement::factory()->create(['unit_id' => null, 'document_type_id' => $type->id, 'name' => "Syarat {$code}", 'is_required' => $required, 'sort_order' => 9]);
}

/**
 * Pendaftar `diterima` (jalur REGULER) dengan tagihan formulir lunas, syarat wajib berkas `valid` ber-file, dan
 * (bila `$invoices`) tagihan penerimaan PSB_TERIMA 8.000.000 yang dibayar `$paid`. Nama "Ahmad Fauzan", NISN
 * `0123456789` (pendaftar berikutnya mendapat NISN acak), wali "Budi Santoso" `081234567890`. `$invoices` false:
 * status `diterima` langsung di database tanpa tagihan penerimaan. Waktu berakhir di 2026-10-15 10:00.
 *
 * @param  array<string, mixed>  $attributes
 */
function daftarUlangApplicant(object $f, array $attributes = [], int $paid = 4000000, string $unit = 'SLTP', bool $invoices = true): Applicant
{
    $applicant = seleksiApplicant(Applicant::STATUS_SUBMITTED, 'REGULER', $unit, false);
    test()->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));

    pendaftaranPayRegistration($applicant);

    $nisn = Applicant::withoutGlobalScopes()->where('nisn', '0123456789')->exists() ? fake()->unique()->numerify('99########') : '0123456789';
    Applicant::withoutGlobalScopes()->whereKey($applicant->id)->update([
        'name' => 'Ahmad Fauzan', 'nisn' => $nisn, 'gender' => 'L', 'birth_place' => 'Bandung', 'birth_date' => '2014-05-12',
        'guardian_name' => 'Budi Santoso', 'guardian_phone' => '081234567890',
        ...Arr::except($attributes, ['status']),
    ]);

    $applicant = Applicant::withoutGlobalScopes()->findOrFail($applicant->id);

    foreach ($applicant->requirements()->where('is_required', true)->get() as $requirement) {
        daftarUlangDocument($applicant, $requirement);
    }

    Applicant::withoutGlobalScopes()->whereKey($applicant->id)->update(['status' => Applicant::STATUS_VERIFIED]);

    if ($invoices) {
        seleksiRun('AcceptApplicant', Applicant::withoutGlobalScopes()->findOrFail($applicant->id), $f->user);
        test()->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));

        $acceptance = seleksiInvoices($applicant, group: 'PSB_TERIMA')->first();

        if ($acceptance !== null && $paid > 0) {
            bayarRecord($acceptance, $paid);
        }
    } else {
        Applicant::withoutGlobalScopes()->whereKey($applicant->id)->update(['status' => Applicant::STATUS_ACCEPTED]);
    }

    return Applicant::withoutGlobalScopes()->findOrFail($applicant->id);
}

/**
 * Jalankan `ReRegisterApplicant` sebagai `$by` (bawaan: admin-psb fixture, tanpa cakupan).
 *
 * @param  array<string, mixed>  $data
 * @return array{student: Student, guardian: Guardian, guardian_reused: bool, invoices: int, fee_overrides: int, documents_copied: int, documents_skipped: list<array{applicant_document_id: int, reason: string}>}
 */
function daftarUlangRun(Applicant $applicant, array $data = [], ?User $by = null): array
{
    $by ??= User::query()->whereHas('roles', fn ($query) => $query->where('name', 'admin-psb'))->oldest('id')->first() ?? psbUser('admin-psb');
    test()->actingAs($by);

    return app(ReRegisterApplicant::class)->handle(Applicant::withoutGlobalScopes()->findOrFail($applicant->id), $by, $data);
}

/**
 * Pola regex untuk pesan `pondok.<group>.errors.<reason>`; placeholder `:nama` bebas isi.
 */
function daftarUlangMessagePattern(string $reason, string $group = 'psb'): string
{
    $parts = preg_split('/:[A-Za-z_]+/', __("pondok.{$group}.errors.{$reason}"));

    return '/^'.implode('.+', array_map(fn (string $part): string => preg_quote($part, '/'), $parts)).'$/su';
}

/**
 * Pastikan callback melempar ValidationException dengan pesan `pondok.<group>.errors.<reason>` (pada `$field` bila diisi).
 */
function daftarUlangAssertRejected(Closure $callback, string $reason, ?string $field = null, string $group = 'psb'): void
{
    expect(__("pondok.{$group}.errors.{$reason}"))->not->toBe("pondok.{$group}.errors.{$reason}");

    try {
        $callback();
    } catch (ValidationException $exception) {
        $errors = $exception->errors();

        if ($field !== null) {
            expect($errors)->toHaveKey($field);
        }

        expect(collect($errors)->flatten()->contains(fn (string $message): bool => preg_match(daftarUlangMessagePattern($reason, $group), $message) === 1))
            ->toBeTrue("Pesan error tidak sesuai pondok.{$group}.errors.{$reason}: ".json_encode($errors));

        return;
    }

    test()->fail('ValidationException diharapkan, tetapi tidak dilempar.');
}

/**
 * Potret keadaan global untuk membuktikan "tanpa perubahan apa pun": jumlah santri (termasuk terhapus), wali, pivot,
 * dokumen, angkatan, activity, daftar file disk `private`, dan pemilik tagihan/keringanan semua pendaftar.
 *
 * @return array<string, mixed>
 */
function daftarUlangSnapshot(): array
{
    return [
        'students' => Student::withoutGlobalScopes()->count(),
        'guardians' => Guardian::query()->count(),
        'pivot' => DB::table('student_guardian')->count(),
        'documents' => Document::withoutUnitScope()->count(),
        'cohorts' => Cohort::query()->count(),
        'activities' => Activity::query()->count(),
        'files' => collect(Storage::disk('private')->allFiles())->sort()->values()->all(),
        'invoices' => Invoice::withoutUnitScope()->orderBy('id')->get(['id', 'student_id', 'applicant_id', 'total', 'paid_total', 'status'])->toArray(),
        'overrides' => FeeOverride::query()->orderBy('id')->get(['id', 'student_id', 'applicant_id', 'mode', 'value', 'valid_from', 'valid_until'])->toArray(),
    ];
}

/**
 * Isi tagihan pendaftar yang tidak boleh berubah saat dipindahkan: tagihan, item, pembayaran, alokasi (tanpa `student_id`/`updated_at`).
 *
 * @return array<string, mixed>
 */
function daftarUlangBilling(Applicant $applicant): array
{
    $invoices = Invoice::withoutUnitScope()->where('applicant_id', $applicant->id)->orderBy('id')->get();

    return [
        'invoices' => $invoices->map(fn (Invoice $invoice): array => $invoice->only(['id', 'invoice_no', 'applicant_id', 'academic_year_id', 'billing_group', 'total', 'paid_total', 'status']))->all(),
        'items' => InvoiceItem::query()->whereIn('invoice_id', $invoices->modelKeys())->orderBy('id')->get(['id', 'invoice_id', 'fee_type_id', 'base_amount', 'discount_amount', 'amount', 'paid_amount', 'fee_override_id'])->toArray(),
        'payments' => Payment::withoutGlobalScopes()->whereIn('invoice_id', $invoices->modelKeys())->orderBy('id')->get(['id', 'invoice_id', 'amount', 'status'])->toArray(),
        'allocations' => DB::table('payment_allocations')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
    ];
}

/**
 * Activity log `$log` (bawaan `psb`) dengan event `$event`, urut id.
 *
 * @return Collection<int, Activity>
 */
function daftarUlangActivities(string $log, string $event): Collection
{
    return Activity::query()->where('log_name', $log)->where('event', $event)->orderBy('id')->get();
}

/**
 * Ganti `$class` di container dengan `$fake` dan kembalikan. Dipakai bersama subkelas anonim yang meneruskan ke instance asli.
 *
 * @template T of object
 *
 * @param  class-string<T>  $class
 * @param  T  $fake
 * @return T
 */
function daftarUlangBind(string $class, object $fake): object
{
    app()->instance($class, $fake);

    return $fake;
}

/*
|--------------------------------------------------------------------------
| Helper portal publik (F017)
|--------------------------------------------------------------------------
|
| Berawalan `portal`. Waktu uji: 2026-10-15 10:00 Asia/Jakarta. Data dibuat langsung (forceFill, tanpa Action)
| lewat helper `konten*` agar HTML mentah bisa dimasukkan untuk test sanitasi ulang.
|
*/

/**
 * Seed jenjang, saklar (konten aktif), halaman statis, kategori album, profil sekolah, waktu dan disk palsu.
 *
 * @return object{sd: Unit, sltp: Unit, slta: Unit}
 */
function portalBoot(bool $freshPages = false): object
{
    test()->withoutVite();
    // Fake bawaan withoutVite() tidak menonaktifkan @fonts: tanpa ini test membaca public/build lokal
    // (hasil berbeda bila aset sudah di-build) dan tag preload font hanya tercetak di request pertama.
    (fn () => $this->buildDirectory = 'build-tidak-ada-saat-test')->call(app(Vite::class));
    test()->seed([UnitSeeder::class, SettingSeeder::class, StaticPageSeeder::class, ContentCategorySeeder::class]);
    // F037: seeder baru memulai profil dan visi-misi sebagai draf. Test portal lama mengandaikan ketiganya terbit;
    // `$freshPages = true` mempertahankan keadaan instalasi baru. Pengecekan kolom menjaga test lama tetap berjalan sebelum migrasi F037 ada.
    if (! $freshPages && Schema::hasColumn('pages', 'status')) {
        StaticPage::query()->update(['status' => 'terbit']);
    }
    Setting::set('modules.konten.enabled', true);
    Setting::set('school.name', "Ma'had Al-Kitab");
    Setting::set('school.tagline', "Mencetak generasi Qur'ani");
    test()->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    Storage::fake('public');

    return (object) [
        'sd' => Unit::query()->where('code', 'SD')->firstOrFail(),
        'sltp' => Unit::query()->where('code', 'SLTP')->firstOrFail(),
        'slta' => Unit::query()->where('code', 'SLTA')->firstOrFail(),
    ];
}

/**
 * Post terbit (bawaan berita, 2026-10-14 08:00 Asia/Jakarta).
 *
 * @param  array<string, mixed>  $attributes
 */
function portalPost(array $attributes = []): Post
{
    return kontenPost([
        'status' => 'terbit',
        'published_at' => CarbonImmutable::parse('2026-10-14 08:00', 'Asia/Jakarta'),
        ...$attributes,
    ]);
}

/**
 * Post terbit yang berasal dari sebuah prestasi (`achievement_id` terisi).
 *
 * @param  array<string, mixed>  $attributes
 */
function portalAchievementPost(array $attributes = []): Post
{
    return portalPost(['achievement_id' => Achievement::factory()->create()->id, ...$attributes]);
}

/**
 * Album dengan sejumlah foto (urut 1..n).
 *
 * @param  array<string, mixed>  $attributes
 */
function portalAlbumWithPhotos(int $count = 2, array $attributes = []): Album
{
    $album = kontenAlbum($attributes);

    for ($i = 1; $i <= $count; $i++) {
        kontenPhoto($album, ['sort_order' => $i]);
    }

    return $album;
}

/**
 * Modul PSB aktif dan satu gelombang terbuka pada tanggal uji.
 */
function portalPsbOpen(array $waveAttributes = []): AdmissionWave
{
    Setting::set('modules.psb.enabled', true);
    $year = AcademicYear::factory()->active()->create(['name' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);

    return psbWave($year, 'Gelombang 1', '2026-10-01', '2026-12-31', $waveAttributes);
}

/**
 * Isi konten lengkap untuk test lintas halaman: post `wisuda-2026`, album `album-ramadan` (2 foto), video.
 *
 * @return object{post: Post, album: Album, video: Video}
 */
function portalSeedContent(): object
{
    return (object) [
        'post' => portalPost(['title' => 'Wisuda 2026']),
        'album' => portalAlbumWithPhotos(2, ['title' => 'Album Ramadan']),
        'video' => kontenVideo(['title' => 'Profil Pondok', 'youtube_id' => 'dQw4w9WgXcQ']),
    ];
}

/**
 * Teks respons dengan entitas HTML didekode (untuk membandingkan URL dengan `&`).
 */
function portalDecoded(TestResponse $response): string
{
    return html_entity_decode($response->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Nilai `aria-label` seluruh `<nav>` pada HTML.
 *
 * @return list<string>
 */
function portalNavLabels(string $html): array
{
    preg_match_all('/<nav\b[^>]*>/i', $html, $tags);

    return array_map(function (string $tag): string {
        preg_match('/aria-label="([^"]*)"/', $tag, $m);

        return $m[1] ?? '';
    }, $tags[0]);
}

/*
|--------------------------------------------------------------------------
| F037 konten-halaman-menu
|--------------------------------------------------------------------------
|
| Data dibuat lewat forceFill (tanpa factory) agar test tidak bergantung pada `StaticPageFactory`.
|
*/

/**
 * Halaman statis (bawaan terbit, berisi).
 *
 * @param  array<string, mixed>  $attributes
 */
function staticPage(array $attributes = []): StaticPage
{
    $token = strtolower(Str::random(8));
    $page = new StaticPage;
    $page->forceFill([
        'slug' => "halaman-{$token}", 'title' => "Halaman {$token}", 'body' => '<p>Isi halaman.</p>', 'status' => 'terbit',
        ...$attributes,
    ])->save();

    return $page;
}

/**
 * Item menu bawaan untuk `portal.menu_items`.
 *
 * @return array{type: string, key: string, visible: bool}
 */
function menuBuiltin(string $key, bool $visible = true): array
{
    return ['type' => 'builtin', 'key' => $key, 'visible' => $visible];
}

/**
 * Item menu halaman untuk `portal.menu_items`.
 *
 * @return array{type: string, page_id: int, label: ?string, visible: bool}
 */
function menuPage(StaticPage|int $page, ?string $label = null, bool $visible = true): array
{
    return ['type' => 'page', 'page_id' => $page instanceof StaticPage ? $page->id : $page, 'label' => $label, 'visible' => $visible];
}

/**
 * Simpan menu langsung ke settings (tanpa Action).
 *
 * @param  list<array<string, mixed>>|null  $items
 */
function menuSet(?array $items, ?bool $showRegister = null): void
{
    Setting::set('portal.menu_items', $items);
    Setting::set('portal.menu_show_register', $showRegister);
}

/**
 * Data kontak minimal agar halaman `kontak` tersedia (aturan Kontak F037).
 */
function portalContactData(): void
{
    Setting::set('school.email', 'info@contoh.test');
}

/**
 * Tautan di dalam kedua `<nav>` header, dikelompokkan per nav (`desktop`, `mobile`).
 *
 * @return array{desktop: list<array{text: string, href: string, current: bool}>, mobile: list<array{text: string, href: string, current: bool}>}
 */
function portalNavLinks(string $html): array
{
    $result = ['desktop' => [], 'mobile' => []];
    $labels = ['desktop' => __('pondok.portal.nav.label'), 'mobile' => __('pondok.portal.nav.mobile_label')];

    foreach ($labels as $layout => $label) {
        $pattern = '#<nav\b[^>]*aria-label="'.preg_quote(e($label), '#').'"[^>]*>(.*?)</nav>#is';

        if (preg_match($pattern, $html, $nav) !== 1) {
            continue;
        }

        preg_match_all('#<a\b([^>]*)>(.*?)</a>#is', $nav[1], $anchors, PREG_SET_ORDER);

        foreach ($anchors as $anchor) {
            preg_match('/href="([^"]*)"/', $anchor[1], $href);
            $result[$layout][] = [
                'text' => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($anchor[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))),
                'href' => html_entity_decode($href[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'current' => str_contains($anchor[1], 'aria-current="page"'),
            ];
        }
    }

    return $result;
}

/**
 * Teks tautan nav (kedua nav harus sama) dalam urutan tampil.
 *
 * @return list<string>
 */
function portalNavTexts(string $html): array
{
    $links = portalNavLinks($html);
    $desktop = array_column($links['desktop'], 'text');

    expect($desktop)->not->toBe([])->and(array_column($links['mobile'], 'text'))->toBe($desktop);

    return $desktop;
}

/**
 * Query SQL (dengan binding) yang berjalan selama `$callback`.
 *
 * @return list<array{sql: string, bindings: array<int, mixed>}>
 */
function recordedQueries(Closure $callback): array
{
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
    });
    $callback();

    return $queries;
}

/**
 * Jumlah query yang menyentuh tabel `$table` (nama dikutip atau tidak).
 *
 * @param  list<array{sql: string, bindings: array<int, mixed>}>  $queries
 */
function queriesOnTable(array $queries, string $table): int
{
    return count(array_filter($queries, fn (array $q): bool => preg_match('/\b(from|into|update|join)\s+[`"\[]?'.preg_quote($table, '/').'[`"\]]?(\s|$)/i', $q['sql']) === 1));
}

/*
|--------------------------------------------------------------------------
| F019 rilis-cpanel
|--------------------------------------------------------------------------
*/

/**
 * Menghapus direktori beserta isinya (termasuk symlink tanpa mengikutinya).
 */
function f019RemoveDirectory(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }

    if (! is_dir($path)) {
        return;
    }

    foreach (scandir($path) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            f019RemoveDirectory($path.'/'.$entry);
        }
    }

    @rmdir($path);
}

/**
 * Direktori sementara unik, dihapus lewat $iso->finish() atau f019RemoveDirectory().
 */
function f019TempDirectory(string $prefix = 'pondok-f019-'): string
{
    $path = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(6));
    mkdir($path, 0777, true);

    return realpath($path);
}

/**
 * Hook isolasi instalasi (rencana F019, Konvensi test). Memindahkan .env, public,
 * storage, dan bootstrap ke direktori sementara, lalu merekam kondisi repo.
 * Panggil `$this->iso->finish()` di afterEach.
 */
function isolateInstallation(): object
{
    $tmp = f019TempDirectory();

    file_put_contents($tmp.'/.env', 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n");

    foreach (['web/build', 'web/js/filament', 'storage/app/private', 'storage/app/public', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $dir) {
        mkdir($tmp.'/'.$dir, 0777, true);
    }

    file_put_contents($tmp.'/web/build/manifest.json', '{}');
    file_put_contents($tmp.'/web/js/filament/app.js', '// filament');

    $repo = [
        'env' => file_exists(base_path('.env')) ? md5_file(base_path('.env')) : null,
        'storage_link' => is_link(base_path('public/storage')) || file_exists(base_path('public/storage')),
        'htaccess' => md5_file(base_path('public/.htaccess')),
        'down' => file_exists(base_path('storage/framework/down')),
        'maintenance' => file_exists(base_path('storage/framework/maintenance.php')),
        'bootstrap_cache' => collect(glob(base_path('bootstrap/cache/*.php')) ?: [])->mapWithKeys(fn ($f) => [$f => filemtime($f)])->all(),
        'filament_dist' => count(glob(base_path('vendor/filament/*/dist'), GLOB_ONLYDIR) ?: []),
        'livewire_dist' => is_dir(base_path('vendor/livewire/livewire/dist')),
        'build_manifest' => is_file(base_path('public/build/manifest.json')),
    ];

    $defaultConnection = config('database.default');

    $app = app();
    $app->useEnvironmentPath($tmp);
    $app->usePublicPath($tmp.'/web');
    $app->useStoragePath($tmp.'/storage');
    $app->useBootstrapPath($tmp.'/bootstrap');

    config([
        'filesystems.disks.local.root' => $tmp.'/storage/app/private',
        'filesystems.disks.private.root' => $tmp.'/storage/app/private',
        'filesystems.disks.public.root' => $tmp.'/storage/app/public',
        'filesystems.links' => [$tmp.'/web/storage' => $tmp.'/storage/app/public'],
        'view.compiled' => $tmp.'/storage/framework/views',
        'pondok.public_path' => null,
    ]);

    return new class($tmp, $repo, $defaultConnection)
    {
        public function __construct(public string $tmp, private array $repo, private string $defaultConnection) {}

        public function finish(): void
        {
            config(['database.default' => $this->defaultConnection]);

            try {
                Artisan::call('up');
            } catch (Throwable) {
            }

            $current = [
                'env' => file_exists(base_path('.env')) ? md5_file(base_path('.env')) : null,
                'storage_link' => is_link(base_path('public/storage')) || file_exists(base_path('public/storage')),
                'htaccess' => md5_file(base_path('public/.htaccess')),
                'down' => file_exists(base_path('storage/framework/down')),
                'maintenance' => file_exists(base_path('storage/framework/maintenance.php')),
                'bootstrap_cache' => collect(glob(base_path('bootstrap/cache/*.php')) ?: [])->mapWithKeys(fn ($f) => [$f => filemtime($f)])->all(),
                'filament_dist' => count(glob(base_path('vendor/filament/*/dist'), GLOB_ONLYDIR) ?: []),
                'livewire_dist' => is_dir(base_path('vendor/livewire/livewire/dist')),
                'build_manifest' => is_file(base_path('public/build/manifest.json')),
            ];

            f019RemoveDirectory($this->tmp);

            expect($current)->toBe($this->repo);
        }
    };
}

/**
 * Menulis berkas fixture (isi = path) di bawah $root. Path berakhiran `/` dibuat sebagai direktori.
 *
 * @param  list<string>  $paths
 */
function releaseFixture(string $root, array $paths): void
{
    foreach ($paths as $path) {
        $full = $root.'/'.$path;
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, 'isi:'.$path);
    }
}

/**
 * Berkas yang wajib ada di paket rilis (AC-2).
 *
 * @return list<string>
 */
function releaseRuntimePaths(): array
{
    return [
        '.htaccess', '.env.example', '.env.production.example', 'artisan', 'composer.json', 'composer.lock', 'VERSION',
        'app/Models/User.php', 'lang/id/pondok.php', 'public/index.php', 'public/.htaccess', 'public/build/manifest.json',
        'public/build/assets/app-abc.css', 'public/js/filament/app.js', 'vendor/autoload.php',
        'storage/framework/views/.gitignore', 'storage/framework/sessions/.gitignore', 'storage/framework/cache/.gitignore',
        'storage/logs/.gitignore', 'storage/app/private/.gitignore', 'storage/app/public/.gitignore', 'bootstrap/cache/.gitignore',
        'docs/instalasi.md', 'docs/prd.md',
    ];
}

/**
 * Berkas yang tidak boleh masuk paket rilis (AC-1).
 *
 * @return list<string>
 */
function releaseExcludedPaths(): array
{
    return [
        'node_modules/a/index.js', 'tests/Feature/XTest.php', '.git/HEAD', '.github/workflows/ci.yml', '.claude/agents/x.md',
        '.ai/rules/x.md', '.env', '.env.production', '.env.backup', '.env.staging', '.env.local', 'config/.env.testing',
        'public/hot', 'public/storage/img.jpg', 'storage/logs/laravel.log', 'storage/framework/views/abc.php',
        'storage/framework/sessions/s1', 'storage/framework/cache/data/x', 'storage/app/private/doc.pdf',
        'storage/app/public/img.jpg', 'storage/app/releases/old.zip', 'bootstrap/cache/config.php', 'bootstrap/cache/packages.php',
        'database/database.sqlite', 'docs/plans/F001-plan.md', 'docs/handoffs/x.md', 'CLAUDE.md', 'AGENTS.md', 'boost.json',
        'feature_list.json', 'phpunit.xml', '.phpunit.result.cache', 'auth.json', 'app/.DS_Store', 'error.log',
    ];
}

/**
 * Daftar entri berkas (bukan direktori) di dalam zip.
 *
 * @return list<string>
 */
function zipEntries(string $zipPath): array
{
    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBeTrue();

    $names = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);

        if (! str_ends_with($name, '/')) {
            $names[] = $name;
        }
    }

    $zip->close();

    return $names;
}

/*
|--------------------------------------------------------------------------
| F036 konten-profil-lembaga
|--------------------------------------------------------------------------
*/

/**
 * Tulis baris settings mentah (tanpa model), nilai di-encode JSON seperti migrasi data.
 */
function profilRawSet(string $key, mixed $value): void
{
    DB::table('settings')->updateOrInsert(['key' => $key], [
        'value' => is_string($value) && str_starts_with($value, 'RAW:') ? substr($value, 4) : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Baca baris settings mentah; `null` bila baris tidak ada atau tidak bisa di-decode.
 */
function profilRawGet(string $key): mixed
{
    $row = DB::table('settings')->where('key', $key)->first();

    return $row === null ? null : json_decode((string) $row->value, true);
}

function profilRawHas(string $key): bool
{
    return DB::table('settings')->where('key', $key)->exists();
}

/**
 * Migrasi data F036 (berkas dimuat ulang tiap panggilan).
 */
function profilMigration(): object
{
    return require database_path('migrations/2026_10_17_000000_move_school_profile_settings.php');
}

/**
 * Pastikan Action menolak input dengan ValidationException pada field (atau awalan field) tertentu.
 */
function profilAssertInvalid(Closure $callback, string $fieldPrefix): void
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        $keys = array_keys($exception->errors());

        expect(collect($keys)->contains(fn (string $key): bool => $key === $fieldPrefix || str_starts_with($key, $fieldPrefix.'.')))
            ->toBeTrue("Galat tidak ada pada {$fieldPrefix}: ".json_encode($keys));

        return;
    }

    test()->fail('ValidationException diharapkan, tetapi tidak dilempar.');
}

/**
 * Semua baris settings `school.*` dan `portal.*` (key => nilai), untuk membandingkan "tanpa perubahan".
 *
 * @return array<string, mixed>
 */
function profilSettingsSnapshot(): array
{
    return DB::table('settings')
        ->where(fn ($query) => $query->where('key', 'like', 'school.%')->orWhere('key', 'like', 'portal.%'))
        ->orderBy('key')
        ->pluck('value', 'key')
        ->all();
}

/**
 * Isi banyak key settings lewat model.
 *
 * @param  array<string, mixed>  $values
 */
function profilSet(array $values): void
{
    foreach ($values as $key => $value) {
        Setting::set($key, $value);
    }
}

/**
 * Item kontak tersimpan.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function profilContact(array $overrides = []): array
{
    return ['key' => 'abcd1234', 'label' => 'Sekretariat', 'name' => null, 'phone' => '081200000001', 'is_whatsapp' => true, ...$overrides];
}

/**
 * Tag HTML pertama bernama `$tag` yang memuat semua `$needles`; `null` bila tidak ada.
 *
 * @param  list<string>  $needles
 */
function profilTag(string $html, string $tag, array $needles): ?string
{
    preg_match_all('/<'.$tag.'\b[^>]*>/i', $html, $matches);

    foreach ($matches[0] as $candidate) {
        if (collect($needles)->every(fn (string $needle): bool => str_contains($candidate, $needle))) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Pola atribut `class="..."` yang memuat semua token kelas (urutan bebas).
 *
 * @param  list<string>  $classes
 */
function profilClassPattern(array $classes): string
{
    $lookaheads = array_map(fn (string $class): string => '(?=(?:[^"]*\s)?'.preg_quote($class, '/').'[\s"])', $classes);

    return 'class="'.implode('', $lookaheads).'[^"]*"';
}

/**
 * Teks tampak halaman: skrip, gaya, dan atribut dibuang, entitas didekode.
 */
function profilVisibleText(string $html): string
{
    $html = preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html);

    return html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Bagian footer portal.
 */
function profilFooter(string $html): string
{
    preg_match('#<footer\b.*?</footer>#is', $html, $match);

    return $match[0] ?? '';
}

/**
 * Bagian header portal.
 */
function profilHeader(string $html): string
{
    preg_match('#<header\b.*?</header>#is', $html, $match);

    return $match[0] ?? '';
}

/**
 * Siapkan instalasi baru untuk portal: seeder instalasi saja, tanpa profil dan tanpa data demo.
 */
function profilFreshInstall(): void
{
    test()->withoutVite();
    (fn () => $this->buildDirectory = 'build-tidak-ada-saat-test')->call(app(Vite::class));
    test()->seed(InstallSeeder::class);
    Storage::fake('public');
    test()->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
}

/*
|--------------------------------------------------------------------------
| F043 konten-navigasi-footer
|--------------------------------------------------------------------------
|
| Item navigasi tersimpan: `{type, label, ...}`; `page_id`, `category_id`, `url`, `children` hanya bila relevan.
| Bandingkan dengan `toEqual` (urutan key tidak penting).
|
*/

/**
 * Item navigasi tersimpan.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function navItem(string $type, ?string $label = null, array $extra = []): array
{
    return ['type' => $type, 'label' => $label, ...$extra];
}

/**
 * @return array{type: string, label: ?string, page_id: int}
 */
function navPage(StaticPage|int $page, ?string $label = null): array
{
    return navItem('page', $label, ['page_id' => $page instanceof StaticPage ? $page->id : $page]);
}

/**
 * @param  list<array<string, mixed>>  $children
 * @return array{type: string, label: ?string, children: list<array<string, mixed>>}
 */
function navGroup(string $label, array $children): array
{
    return navItem('group', $label, ['children' => $children]);
}

/**
 * Simpan navigasi langsung ke settings (tanpa Action). `null` = key tidak ada (bawaan).
 * Footer bukan bagian navigasi lagi (F046): atur lewat `ftrSet()`.
 *
 * @param  list<array<string, mixed>>|null  $primary
 * @param  array<string, mixed>|false|null  $headerButton
 */
function navSet(?array $primary = null, array|false|null $headerButton = null): void
{
    foreach (['portal.navigation_primary' => $primary, 'portal.header_button' => $headerButton] as $key => $value) {
        if ($value === null) {
            Setting::query()->whereKey($key)->delete();
        } else {
            Setting::set($key, $value);
        }
    }

    app()->forgetScopedInstances();
}

/**
 * Hasil `GetPortalNavigation` untuk profil saat ini.
 *
 * @return array{primary: list<array<string, mixed>>, headerButton: ?array<string, mixed>}
 */
function navResolve(): array
{
    app()->forgetScopedInstances();

    return app(GetPortalNavigation::class)->handle(app(GetSchoolProfile::class)->handle());
}

/**
 * Label hasil navigasi; grup menjadi `Label > [anak, ...]`.
 *
 * @param  list<array<string, mixed>>  $links
 * @return list<string|array<string, list<string>>>
 */
function navLabels(array $links): array
{
    return array_map(fn (array $link): string|array => isset($link['children']) ? [$link['label'] => array_column($link['children'], 'label')] : $link['label'], $links);
}

/**
 * Migrasi data F043 (berkas dimuat ulang tiap panggilan).
 */
function navMigration(): object
{
    return require database_path('migrations/2026_10_19_000000_move_portal_navigation_settings.php');
}

/**
 * Konten publik minimal agar tujuan berita, galeri, dan video tersedia.
 */
function navSeedPublicContent(): void
{
    portalPost(['title' => 'Berita Uji']);
    portalAlbumWithPhotos(1, ['title' => 'Album Uji']);
    kontenVideo(['title' => 'Video Uji']);
}

/**
 * HTML satu `<nav>` header (`desktop` atau `mobile`).
 */
function navHtml(string $html, string $layout): string
{
    $label = $layout === 'desktop' ? __('pondok.portal.nav.label') : __('pondok.portal.nav.mobile_label');
    preg_match('#<nav\b[^>]*aria-label="'.preg_quote(e($label), '#').'"[^>]*>(.*?)</nav>#is', $html, $match);

    return $match[1] ?? '';
}

/**
 * Teks tautan yang aktif pada kedua nav (harus sama).
 *
 * @return list<string>
 */
function navCurrent(string $html): array
{
    $links = portalNavLinks($html);
    $desktop = collect($links['desktop'])->where('current', true)->pluck('text')->all();

    expect(collect($links['mobile'])->where('current', true)->pluck('text')->all())->toBe($desktop);

    return $desktop;
}

/**
 * Label item grup menu admin "Situs" (urutan tampil) untuk user; kosong bila grup tidak tampil.
 *
 * @return list<string>
 */
function situsNavigationLabels(User $user): array
{
    test()->actingAs($user);
    Filament::setCurrentPanel('admin');

    foreach (Filament::getNavigation() as $group) {
        if ($group->getLabel() === __('pondok.navigation.situs')) {
            return collect($group->getItems())->map(fn ($item): string => (string) $item->getLabel())->values()->all();
        }
    }

    return [];
}

/**
 * Memastikan setiap kunci lang punya teks bahasa Indonesia yang tidak kosong. Dipakai sebagai satu test per daftar,
 * bukan dataset per kunci: setiap kasus dataset Feature menjalankan ulang migrasi, padahal pengecekan ini tanpa DB.
 * Bila gagal, pesan menyebut semua kunci yang hilang sekaligus.
 *
 * @param  iterable<mixed>|Closure(): iterable<mixed>  $keys  kunci lengkap, atau baris dataset `[kunci]`
 */
function expectTranslatedKeys(iterable|Closure $keys): void
{
    $keys = collect($keys instanceof Closure ? $keys() : $keys)->flatten();

    $missing = $keys
        ->reject(fn (string $key): bool => Lang::has($key, 'id') && is_string(__($key)) && __($key) !== $key && trim(__($key)) !== '')
        ->values()
        ->all();

    expect($keys)->not->toBeEmpty()
        ->and($missing)->toBe([], 'Kunci lang tanpa teks: '.implode(', ', $missing));
}

/*
|--------------------------------------------------------------------------
| F045 konten-beranda-blok
|--------------------------------------------------------------------------
|
| Blok beranda tersimpan di `portal.home_blocks` sebagai daftar `{type, visible, data}`.
| Input `UpdatePortalHome` dan state Builder memakai bentuk yang sama.
|
*/

/**
 * Satu blok beranda.
 *
 * @param  array<string, mixed>  $data
 * @return array{type: string, visible: bool, data: array<string, mixed>}
 */
function blk(string $type, array $data = [], bool $visible = true): array
{
    return ['type' => $type, 'visible' => $visible, 'data' => $data];
}

/**
 * Data minimal yang valid untuk tiap tipe blok (`video` membutuhkan `$videoId`).
 *
 * @return array<string, mixed>
 */
function blkValidData(string $type, ?int $videoId = null): array
{
    return match ($type) {
        'hero' => ['title' => 'Judul hero'],
        'text_image' => ['heading' => 'Tentang kami', 'text' => '<p>Isi singkat.</p>'],
        'features' => ['items' => [['title' => 'Satu']]],
        'quote' => ['text' => 'نص الاختبار'],
        'cta' => ['heading' => 'Ajakan uji', 'variant' => 'light'],
        'video' => ['video_id' => $videoId],
        'feed' => ['source' => 'posts'],
    };
}

function blkActor(): User
{
    if (! isset(test()->blkActor)) {
        test()->blkActor = kontenUser('admin-konten');
    }

    return test()->blkActor;
}

/**
 * Simpan lewat Action `UpdatePortalHome` (input `{home_blocks: [...]}`).
 *
 * @param  list<array<string, mixed>>  $blocks
 */
function blkSave(array $blocks): void
{
    app(UpdatePortalHome::class)->handle(['home_blocks' => $blocks], blkActor());
    app()->forgetScopedInstances();
}

/**
 * Blok tersimpan (mentah dari tabel `settings`).
 *
 * @return list<array<string, mixed>>
 */
function blkStored(): array
{
    return profilRawGet('portal.home_blocks') ?? [];
}

/**
 * Tulis blok langsung ke settings tanpa Action (meniru data lama, hasil migrasi, atau ubahan manual).
 *
 * @param  list<array<string, mixed>>  $blocks
 */
function blkSet(array $blocks): void
{
    profilRawSet('portal.home_blocks', $blocks);
    app()->forgetScopedInstances();
}

/**
 * HTML beranda portal (status 200).
 */
function blkHome(): string
{
    return test()->get('/')->assertOk()->getContent();
}

/**
 * Migrasi data F045 (berkas dimuat ulang tiap panggilan).
 */
function blkMigration(): object
{
    return require database_path('migrations/2026_10_20_000000_move_portal_home_to_blocks.php');
}

/**
 * Pastikan simpan ditolak dengan galat pada salah satu field `$fields` dan settings tidak berubah.
 *
 * @param  list<array<string, mixed>>  $blocks
 * @param  string|list<string>  $fields
 */
function blkAssertRejected(array $blocks, string|array $fields, string $label): void
{
    $before = profilSettingsSnapshot();
    $fields = (array) $fields;

    try {
        blkSave($blocks);
    } catch (ValidationException $exception) {
        $keys = array_keys($exception->errors());

        expect(collect($keys)->contains(fn (string $key): bool => in_array($key, $fields, true)))
            ->toBeTrue("{$label}: galat di ".json_encode($keys).', diharapkan '.json_encode($fields));
        expect(profilSettingsSnapshot())->toBe($before, "{$label}: settings berubah");

        return;
    }

    test()->fail("{$label}: ValidationException diharapkan, tetapi tidak dilempar.");
}

/*
|--------------------------------------------------------------------------
| F046 konten-footer-widget
|--------------------------------------------------------------------------
|
| Widget footer disimpan di `portal.footer_widgets` sebagai daftar `{type, visible, data}` (bentuk sama dengan
| blok beranda, jadi `blk()` dipakai untuk membuat satu widget). Tipe: text, links, social, contacts.
*/

/**
 * Satu widget footer (alias `blk`).
 *
 * @param  array<string, mixed>  $data
 * @return array{type: string, visible: bool, data: array<string, mixed>}
 */
function ftr(string $type, array $data = [], bool $visible = true): array
{
    return blk($type, $data, $visible);
}

function ftrActor(): User
{
    return blkActor();
}

/**
 * Simpan lewat Action `UpdatePortalFooter` (input `{footer_widgets: [...]}`).
 *
 * @param  list<array<string, mixed>>  $widgets
 */
function ftrSave(array $widgets): void
{
    app(UpdatePortalFooter::class)->handle(['footer_widgets' => $widgets], ftrActor());
    app()->forgetScopedInstances();
}

/**
 * Widget tersimpan (mentah dari tabel `settings`).
 *
 * @return list<array<string, mixed>>
 */
function ftrStored(): array
{
    return profilRawGet('portal.footer_widgets') ?? [];
}

/**
 * Tulis widget langsung ke settings tanpa Action (data lama, hasil migrasi, atau ubahan manual).
 *
 * @param  list<array<string, mixed>>  $widgets
 */
function ftrSet(array $widgets): void
{
    profilRawSet('portal.footer_widgets', $widgets);
    app()->forgetScopedInstances();
}

/**
 * Migrasi data F046 (berkas dimuat ulang tiap panggilan).
 */
function ftrMigration(): object
{
    return require database_path('migrations/2026_10_20_000001_move_portal_footer_to_widgets.php');
}

/**
 * Pastikan simpan ditolak dengan galat pada salah satu field `$fields` dan settings tidak berubah.
 *
 * @param  list<array<string, mixed>>  $widgets
 * @param  string|list<string>  $fields
 */
function ftrAssertRejected(array $widgets, string|array $fields, string $label): void
{
    $before = profilSettingsSnapshot();
    $fields = (array) $fields;

    try {
        ftrSave($widgets);
    } catch (ValidationException $exception) {
        $keys = array_keys($exception->errors());

        expect(collect($keys)->contains(fn (string $key): bool => in_array($key, $fields, true)))
            ->toBeTrue("{$label}: galat di ".json_encode($keys).', diharapkan '.json_encode($fields));
        expect(profilSettingsSnapshot())->toBe($before, "{$label}: settings berubah");

        return;
    }

    test()->fail("{$label}: ValidationException diharapkan, tetapi tidak dilempar.");
}

/**
 * Isi footer lama (F043) langsung ke settings, untuk menguji migrasi.
 *
 * @param  array<string, mixed>  $overrides
 */
function ftrLegacy(array $overrides = []): void
{
    $values = [
        'portal.footer_description' => 'Deskripsi lembaga uji',
        'portal.footer_text' => ['title' => 'Jam Layanan', 'body' => "Senin sampai Jumat\n08.00 sampai 15.00"],
        'portal.navigation_footer' => [navItem('url', 'Berita Pilihan', ['url' => '/berita']), navItem('url', 'Situs Mitra', ['url' => 'https://contoh.test/mitra'])],
        ...$overrides,
    ];

    foreach ($values as $key => $value) {
        if ($value === null) {
            DB::table('settings')->where('key', $key)->delete();
        } else {
            profilRawSet($key, $value);
        }
    }
}

/*
|--------------------------------------------------------------------------
| Helper import saldo awal (F060)
|--------------------------------------------------------------------------
|
| Berawalan `saldo`. Waktu uji: 2026-10-15 10:00 Asia/Jakarta, santri NIS 2026001
| di SLTP, jenis biaya SPP/ASRAMA/MAKAN dari `tagihanFixture`.
|
*/

/**
 * @return object{f: object, student: Student}
 */
function saldoFixture(bool $withRates = false): object
{
    test()->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    $f = tagihanFixture($withRates);

    return (object) ['f' => $f, 'student' => tagihanStudent($f, attributes: ['nis' => '2026001'])];
}

/**
 * @param  array<string, string>  $overrides
 * @return array{nis: string, fee_type: string, amount: string}
 */
function saldoRow(array $overrides = []): array
{
    return ['nis' => '2026001', 'fee_type' => 'SPP', 'amount' => '1500000', ...$overrides];
}

/**
 * Tagihan saldo awal aktif milik santri (tanpa cakupan jenjang).
 *
 * @return Collection<int, Invoice>
 */
function saldoInvoices(?Student $student = null): Collection
{
    return Invoice::withoutUnitScope()
        ->where('billing_group', 'SALDO_AWAL')
        ->when($student, fn ($query) => $query->where('student_id', $student->id))
        ->orderBy('id')
        ->get();
}

/*
|--------------------------------------------------------------------------
| Helper Info PSB publik (F038)
|--------------------------------------------------------------------------
|
| Berawalan `psbInfo`. Kontrak: `App\Actions\Konten\GetPsbInfo::handle(): array` dengan kunci
| page, bodyHtml, waves, requirements, fees, contacts. Route `psb.info` = `/info-psb`.
|
*/

/**
 * Portal siap uji dengan modul psb aktif, tanpa menu dan tombol header lain (agar tautan Daftar hanya dari halaman).
 *
 * @return object{sd: Unit, sltp: Unit, slta: Unit}
 */
function psbInfoBoot(): object
{
    $f = portalBoot();
    Setting::set('modules.psb.enabled', true);
    navSet([navItem('home')], false);

    return $f;
}

/**
 * Halaman Info PSB lewat factory (tanpa Action).
 *
 * @param  array<string, mixed>  $attributes
 */
function psbInfoPage(array $attributes = []): StaticPage
{
    return StaticPage::factory()->create([
        'slug' => 'info-psb',
        'title' => 'Informasi Pendaftaran',
        'template' => 'psb-info',
        ...$attributes,
    ]);
}

/**
 * Gelombang yang sedang berlangsung pada tanggal uji (2026-10-15) di tahun ajaran aktif, dibuat bila belum ada.
 *
 * @param  array<string, mixed>  $attributes
 */
function psbInfoWave(string $name = 'Gelombang 1', string $opens = '2026-10-01', string $closes = '2026-12-31', array $attributes = []): AdmissionWave
{
    $year = AcademicYear::query()->first()
        ?? AcademicYear::factory()->active()->create(['name' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);

    return psbWave($year, $name, $opens, $closes, $attributes);
}

/**
 * Kontak lembaga dengan tanda PSB (disimpan langsung ke settings).
 *
 * @param  list<array<string, mixed>>  $contacts
 */
function psbInfoSetContacts(array $contacts): void
{
    Setting::set('school.contacts', $contacts);
    app()->forgetScopedInstances();
}

/**
 * Jumlah elemen `<$tag` pada HTML.
 */
function psbInfoCountTag(string $html, string $tag): int
{
    return preg_match_all('/<'.$tag.'\b/i', $html);
}

/**
 * Posisi pertama `$needle` pada teks tampilan (tag dibuang), untuk memeriksa urutan.
 */
function psbInfoPosition(string $html, string $needle): int|false
{
    return strpos(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'))), $needle);
}

/*
|--------------------------------------------------------------------------
| Helper tagihan tahunan (F041)
|--------------------------------------------------------------------------
|
| Berawalan `tahunan`. Memakai `tagihanFixture` dan `tagihanStudent`.
|
*/

/**
 * Jenis biaya tahunan (DAFTAR_ULANG, semua santri) dengan tarif jenjang SLTP, dan bulan tagihan tahunan di setting.
 *
 * @param  array<string, mixed>  $attributes
 */
function tahunanFeeType(object $f, int $amount = 750000, array $attributes = [], ?int $month = 7): FeeType
{
    $type = FeeType::factory()->create([
        'code' => 'DAFTAR_ULANG',
        'name' => 'Daftar ulang',
        'frequency' => FeeType::FREQUENCY_YEARLY,
        'billing_group' => 'DAFTAR_ULANG',
        'priority' => 4,
        ...$attributes,
    ]);

    keuanganRate($type, $f->year, $f->sltp, $amount);
    Setting::set('keuangan.annual_billing_month', $month);

    return $type;
}

/**
 * Jalankan generator tahunan dari container.
 *
 * @return array{period: string, created_invoices: int, added_items: int, existing_invoices: int, skipped: list<array{student_id: int, fee_type_id: int, reason: string}>}
 */
function tahunanGenerate(AcademicYear $year, ?User $by = null): array
{
    return app(GenerateAnnualInvoices::class)->handle($year, $by);
}

/**
 * Tagihan aktif (bukan batal) santri untuk periode dan grup tahunan, tanpa cakupan jenjang.
 */
function tahunanInvoiceFor(Student $student, string $group = 'DAFTAR_ULANG', string $period = '2026-07-01'): ?Invoice
{
    return tagihanInvoiceFor($student, $group, $period);
}

/*
|--------------------------------------------------------------------------
| Helper F052 (performa dan keandalan keuangan)
|--------------------------------------------------------------------------
|
| Berawalan `invoiceDatabase`. Hasil generate manual kini dikirim sebagai
| notifikasi database oleh `GenerateInvoicesJob`, bukan toast.
|
*/

/**
 * Notifikasi database user sebagai teks `status|judul isi`, satu baris per notifikasi.
 */
function invoiceDatabaseNotificationText(User $user): string
{
    return $user->notifications()->get()
        ->map(fn ($notification): string => ($notification->data['status'] ?? '').'|'.($notification->data['title'] ?? '').' '.($notification->data['body'] ?? ''))
        ->implode("\n");
}

/*
|--------------------------------------------------------------------------
| Helper F070 (tema portal)
|--------------------------------------------------------------------------
|
| Berawalan `theme`. `themeBuild` mengarahkan public path ke direktori sementara berisi
| `build/manifest.json` dan `build/fonts-manifest.json` fiktif, sehingga hasil tidak
| bergantung pada aset yang sudah di-build di mesin pengembang.
|
*/

/** Skala bawaan `resources/css/app.css` (design guide §2) dan langkah skala. */
const THEME_STEPS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

const THEME_DEFAULT_PRIMARY = [
    50 => '#eef2fb', 100 => '#dde4f6', 200 => '#bbc8ec', 300 => '#8ea2db', 400 => '#5f78c4', 500 => '#3d56a8',
    600 => '#273e8c', 700 => '#1a2c72', 800 => '#0f1d59', 900 => '#061247', 950 => '#000a3c',
];

const THEME_DEFAULT_ACCENT = [
    50 => '#fff9e5', 100 => '#fff0bf', 200 => '#ffe28a', 300 => '#ffd452', 400 => '#ffc524', 500 => '#f8b800',
    600 => '#d49a00', 700 => '#a67700', 800 => '#7a5700', 900 => '#523a00', 950 => '#2e2100',
];

/**
 * Tulis key `theme.*` langsung (tanpa Action). Contoh: `themeSet(['primary' => '#1f2a5e'])`.
 *
 * @param  array<string, mixed>  $values
 */
function themeSet(array $values): void
{
    foreach ($values as $key => $value) {
        Setting::set("theme.{$key}", $value);
    }
}

/**
 * Public path sementara dengan manifest font fiktif. `$fontAliases` = alias di `families`;
 * string = isi mentah berkas (mis. JSON rusak); null = tanpa berkas manifest font.
 *
 * @param  list<string>|string|null  $fontAliases
 */
function themeBuild(array|string|null $fontAliases = ['plus-jakarta-sans', 'lora', 'nunito', 'noto-naskh-arabic']): string
{
    $dir = sys_get_temp_dir().'/pondok-theme-'.Str::random(12);
    mkdir($dir.'/build', 0777, true);

    file_put_contents($dir.'/build/manifest.json', json_encode([
        'resources/css/app.css' => ['file' => 'assets/app-test.css', 'src' => 'resources/css/app.css', 'isEntry' => true],
    ]));

    if (is_array($fontAliases)) {
        file_put_contents($dir.'/build/fonts-manifest.json', json_encode([
            'version' => 1,
            'families' => array_fill_keys($fontAliases, ['family' => 'Uji']),
            'preloads' => [],
        ]));
    } elseif (is_string($fontAliases)) {
        file_put_contents($dir.'/build/fonts-manifest.json', $fontAliases);
    }

    app()->usePublicPath($dir);

    return $dir;
}

/*
|--------------------------------------------------------------------------
| Helper Backup & masa simpan (F042)
|--------------------------------------------------------------------------
|
| Berawalan `retention`. Tes berjalan di SQLite; jalur khusus Postgres diuji dengan
| menyamarkan nama driver koneksi (query tetap lewat grammar SQLite).
|
*/

/**
 * Arahkan `storage_path()` ke folder per proses paralel. Folder sementara backup
 * (`app/backup-tmp`) dibersihkan dan diperiksa test; tanpa ini proses lain bisa
 * menghapus folder backup yang sedang dibuat.
 */
function backupIsolateStorage(): void
{
    app()->useStoragePath(storage_path('framework/testing/backup-'.(ParallelTesting::token() ?: 'serial')));
}

/**
 * Buat koneksi bawaan melaporkan driver `pgsql` sampai aplikasi test dihancurkan.
 * Berlaku untuk `DB::getDriverName()` maupun `config('database.connections.<default>.driver')`.
 */
function retentionPretendPgsql(): void
{
    $name = (string) config('database.default');
    $connection = DB::connection($name);
    $property = new ReflectionProperty($connection, 'config');
    $original = $property->getValue($connection);

    $property->setValue($connection, [...$original, 'driver' => 'pgsql']);
    config(["database.connections.{$name}.driver" => 'pgsql']);

    test()->beforeApplicationDestroyed(function () use ($property, $connection, $original, $name): void {
        $property->setValue($connection, $original);
        config(["database.connections.{$name}.driver" => $original['driver']]);
    });
}

/*
|--------------------------------------------------------------------------
| Helper MFA & verifikasi email (F055)
|--------------------------------------------------------------------------
|
| Berawalan `mfa`. Memakai kontrak `HasAppAuthentication` dan `HasAppAuthenticationRecovery`
| milik Filament pada `User`, persis seperti alur profil.
|
*/

/**
 * Aktifkan MFA aplikasi untuk user lewat provider Filament (secret dan recovery code tersimpan terenkripsi/hash).
 *
 * @return array{secret: string, codes: list<string>}
 */
function mfaEnable(User $user): array
{
    $provider = AppAuthentication::make()->recoverable();
    $secret = $provider->generateSecret();
    $codes = $provider->generateRecoveryCodes();

    $provider->saveSecret($user, $secret);
    $provider->saveRecoveryCodes($user, $codes);

    return ['secret' => $secret, 'codes' => $codes];
}

/**
 * Kolom MFA mentah di database (tanpa cast), untuk memastikan data benar-benar kosong atau terenkripsi.
 *
 * @return array{secret: ?string, codes: ?string}
 */
function mfaRaw(User $user): array
{
    $row = DB::table('users')->where('id', $user->id)->first(['app_authentication_secret', 'app_authentication_recovery_codes']);

    return ['secret' => $row->app_authentication_secret, 'codes' => $row->app_authentication_recovery_codes];
}

/*
|--------------------------------------------------------------------------
| Helper portal wali (F022, dipindah ke Blade + Livewire oleh F079)
|--------------------------------------------------------------------------
|
| Berawalan `wali`. Memakai `keuanganUser`, `asramaResident`, `asramaRoom`, `dokumenCreate`,
| `dokumenType`, `bayarHtmlText`. Saklar `portal-wali` diatur dengan key string (bukan enum)
| agar test tidak bergantung pada seeder. Waktu uji: 2026-10-15 10:00 Asia/Jakarta.
|
*/

/**
 * Fixture portal wali: seeder, saklar semua modul aktif (termasuk `portal-wali`), disk private palsu,
 * tahun ajaran aktif, jenjang SLTP/SD, santri bersaudara `s1` (Ahmad Satu, NIS 1001) dan `s2` (Budi Dua, NIS 1002)
 * milik wali `wali` (user berperan wali, verified), serta santri `o1` (Citra Lain) milik wali lain `other`.
 *
 * @return object{year: AcademicYear, sltp: Unit, sd: Unit, cohort: Cohort, wali: User, guardian: Guardian, s1: Student, s2: Student, other: User, otherGuardian: Guardian, o1: Student}
 */
function waliBoot(): object
{
    test()->seed([UnitSeeder::class, DocumentTypeSeeder::class, RolePermissionSeeder::class, SettingSeeder::class]);

    foreach (['akademik', 'asrama', 'keuangan', 'dokumen', 'portal-wali'] as $module) {
        Setting::set("modules.{$module}.enabled", true);
    }

    test()->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    Storage::fake('private');
    Storage::fake('public');

    $sltp = Unit::query()->where('code', 'SLTP')->firstOrFail();
    $cohort = Cohort::factory()->create(['unit_id' => $sltp->id, 'entry_year' => 2026]);

    $wali = User::factory()->create(['email' => 'wali.satu@contoh.test'])->assignRole('wali');
    $guardian = waliGuardianFor($wali, 'Wali Satu');
    $other = User::factory()->create(['email' => 'wali.lain@contoh.test'])->assignRole('wali');
    $otherGuardian = waliGuardianFor($other, 'Wali Lain');

    $s1 = Student::factory()->forCohort($cohort)->create(['name' => 'Ahmad Satu', 'nis' => '1001']);
    $s2 = Student::factory()->forCohort($cohort)->create(['name' => 'Budi Dua', 'nis' => '1002']);
    $o1 = Student::factory()->forCohort($cohort)->create(['name' => 'Citra Lain', 'nis' => '1003']);
    $s1->guardians()->attach($guardian->id, ['relation' => 'ayah', 'is_billing_contact' => true]);
    $s2->guardians()->attach($guardian->id, ['relation' => 'ayah', 'is_billing_contact' => true]);
    $o1->guardians()->attach($otherGuardian->id, ['relation' => 'ayah', 'is_billing_contact' => true]);

    return (object) [
        'year' => AcademicYear::factory()->active()->create(['name' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']),
        'sltp' => $sltp,
        'sd' => Unit::query()->where('code', 'SD')->firstOrFail(),
        'cohort' => $cohort,
        'wali' => $wali,
        'guardian' => $guardian,
        's1' => $s1,
        's2' => $s2,
        'other' => $other,
        'otherGuardian' => $otherGuardian,
        'o1' => $o1,
    ];
}

/**
 * Wali yang tertaut ke user (`user_id` diisi lewat forceFill, seperti Action produksi).
 */
function waliGuardianFor(User $user, string $name): Guardian
{
    $guardian = Guardian::factory()->create(['name' => $name]);
    $guardian->forceFill(['user_id' => $user->id])->save();

    return $guardian->fresh();
}

/**
 * Tagihan aktif santri dengan satu item (base 500.000, diskon 100.000, amount 400.000) kecuali `$attributes` menimpa.
 * Total/paid_total/status diatur lewat `$attributes` (bawaan: total 400.000, belum bayar).
 *
 * @param  array<string, mixed>  $attributes
 */
function waliInvoice(object $f, Student $student, array $attributes = [], string $period = '2026-10-01'): Invoice
{
    $invoice = Invoice::factory()->create([
        'student_id' => $student->id,
        'academic_year_id' => $f->year->id,
        'period' => CarbonImmutable::parse($period, 'Asia/Jakarta')->startOfMonth(),
        'total' => 400000,
        'paid_total' => 0,
        'status' => Invoice::STATUS_UNPAID,
        'due_on' => '2026-11-10',
        ...$attributes,
    ]);

    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'fee_type_id' => FeeType::factory()->create(['name' => 'SPP '.$invoice->id])->id,
        'base_amount' => 500000,
        'discount_amount' => 100000,
        'amount' => 400000,
    ]);

    return $invoice;
}

/**
 * Teks polos halaman portal `/wali...` (Blade, F079) untuk user (harus 200).
 */
function waliText(User $user, string $path = '/wali'): string
{
    return bayarHtmlText(test()->actingAs($user)->get($path)->assertOk()->getContent());
}

/**
 * Atur ulang password lewat komponen Livewire portal wali (token dari broker, email diisi manual).
 */
function waliReset(User $user, string $token, string $password, ?string $confirmation = null, bool $invitation = false): Testable
{
    Livewire::withQueryParams($invitation ? ['undangan' => 1] : []);

    return Livewire::test(PortalResetPassword::class, ['token' => $token])
        ->set('email', $user->email)->set('password', $password)->set('password_confirmation', $confirmation ?? $password)
        ->call('resetPassword');
}

/**
 * Pastikan callback melempar ValidationException dengan error pada `$field`.
 */
function waliAssertRejected(Closure $callback, string $field = 'email'): void
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);

        return;
    }

    test()->fail('ValidationException diharapkan, tetapi tidak dilempar.');
}

/*
|--------------------------------------------------------------------------
| F023 tahfidz
|--------------------------------------------------------------------------
|
| Berawalan `tahfidz`. Baris model dibuat dengan `forceCreate` (tanpa Action) agar test Action bisa
| menyiapkan data tanpa bergantung pada aturan yang sedang diuji. Tanggal "hari ini" = 2026-10-15.
|
*/

/**
 * Seeder, saklar modul, tahun ajaran aktif 2026/2027, dan waktu tetap.
 *
 * @return object{year: AcademicYear, sltp: Unit, slta: Unit}
 */
function tahfidzBoot(): object
{
    test()->seed([RolePermissionSeeder::class, SettingSeeder::class, UnitSeeder::class]);
    Setting::set('modules.tahfidz.enabled', true);
    Filament::setCurrentPanel('admin');
    test()->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));

    return (object) [
        'year' => AcademicYear::factory()->active()->create(['name' => '2026/2027', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']),
        'sltp' => Unit::query()->where('code', 'SLTP')->firstOrFail(),
        'slta' => Unit::query()->where('code', 'SLTA')->firstOrFail(),
    ];
}

/**
 * Akun ustadz-tahfidz beserta baris staff aktif miliknya (`$user->staff`).
 *
 * @param  list<string>  $unitCodes
 * @param  array<string, mixed>  $staffAttributes
 */
function tahfidzUstadz(array $unitCodes = [], array $staffAttributes = []): User
{
    $user = asramaUser('ustadz-tahfidz', $unitCodes);
    Staff::factory()->create(['user_id' => $user->id, 'gender' => 'L', 'position' => 'ustadz', ...$staffAttributes]);

    return User::query()->findOrFail($user->id);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function tahfidzStaff(array $attributes = []): Staff
{
    return Staff::factory()->create(['gender' => 'L', 'position' => 'ustadz', ...$attributes]);
}

/**
 * Halaqah putra aktif di tahun ajaran aktif, jenjang SLTP. `unit_id => null` = lintas jenjang.
 *
 * @param  array<string, mixed>  $attributes
 */
function tahfidzHalaqah(object $f, ?Staff $teacher = null, array $attributes = []): Halaqah
{
    return Halaqah::query()->forceCreate([
        'academic_year_id' => $f->year->id,
        'unit_id' => $f->sltp->id,
        'teacher_staff_id' => ($teacher ?? tahfidzStaff())->id,
        'name' => 'Halaqah '.fake()->unique()->lexify('?????'),
        'gender' => 'L',
        'schedule' => null,
        'is_active' => true,
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function tahfidzStudent(object $f, string $gender = 'L', ?Unit $unit = null, array $attributes = []): Student
{
    $cohort = Cohort::factory()->create(['unit_id' => ($unit ?? $f->sltp)->id]);

    return Student::factory()->forCohort($cohort)->create(['gender' => $gender, ...$attributes]);
}

function tahfidzMember(Halaqah $halaqah, Student $student, string $joinedOn = '2026-07-01', ?string $leftOn = null): HalaqahMember
{
    return HalaqahMember::query()->forceCreate([
        'halaqah_id' => $halaqah->id,
        'student_id' => $student->id,
        'joined_on' => $joinedOn,
        'left_on' => $leftOn,
    ]);
}

/**
 * Halaqah putra SLTP dengan satu santri anggota sejak awal tahun ajaran.
 *
 * @return array{0: Halaqah, 1: Student}
 */
function tahfidzHalaqahWithMember(object $f, ?Staff $teacher = null): array
{
    $halaqah = tahfidzHalaqah($f, $teacher);
    $student = tahfidzStudent($f);
    tahfidzMember($halaqah, $student);

    return [$halaqah, $student];
}

function tahfidzAdmin(): User
{
    return asramaUser('super-admin');
}

/**
 * Data `RecordMemorization`: ziyadah An-Naba' 1-5 kemarin, penyimak = pengampu halaqah.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tahfidzRecordData(Halaqah $halaqah, Student $student, array $overrides = []): array
{
    return [
        'student_id' => $student->id,
        'halaqah_id' => $halaqah->id,
        'recorded_on' => '2026-10-14',
        'session' => 'subuh',
        'type' => 'ziyadah',
        'from_surah_id' => 78,
        'from_ayah' => 1,
        'to_surah_id' => 78,
        'to_ayah' => 5,
        'pages' => 0.5,
        'grade' => 'jayyid',
        'mistakes' => 1,
        'note' => null,
        'listener_staff_id' => $halaqah->teacher_staff_id,
        ...$overrides,
    ];
}

/**
 * Setoran lewat Action.
 *
 * @param  array<string, mixed>  $overrides
 */
function tahfidzRecord(User $actor, Halaqah $halaqah, Student $student, array $overrides = []): MemorizationRecord
{
    return app(RecordMemorization::class)->handle($actor, tahfidzRecordData($halaqah, $student, $overrides));
}

/**
 * Setoran langsung ke tabel (tanpa validasi) lalu kembalikan barisnya.
 *
 * @param  array<string, mixed>  $overrides
 */
function tahfidzRawRecord(Halaqah $halaqah, Student $student, array $overrides = []): MemorizationRecord
{
    return MemorizationRecord::query()->forceCreate(tahfidzRecordData($halaqah, $student, $overrides));
}

/**
 * Data `RecordTahfidzExam`: kenaikan juz 30 lulus.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tahfidzExamData(Halaqah $halaqah, Student $student, array $overrides = []): array
{
    return [
        'student_id' => $student->id,
        'halaqah_id' => $halaqah->id,
        'kind' => 'kenaikan_juz',
        'juz_from' => 30,
        'juz_to' => 30,
        'exam_on' => '2026-10-14',
        'score' => 85,
        'result' => 'lulus',
        'certificate_no' => null,
        'examiner_staff_id' => $halaqah->teacher_staff_id,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function tahfidzExam(User $actor, Halaqah $halaqah, Student $student, array $overrides = []): TahfidzExam
{
    return app(RecordTahfidzExam::class)->handle($actor, tahfidzExamData($halaqah, $student, $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function tahfidzRawExam(Halaqah $halaqah, Student $student, array $overrides = []): TahfidzExam
{
    return TahfidzExam::query()->forceCreate(tahfidzExamData($halaqah, $student, $overrides));
}

/**
 * Capaian juz santri: juz => [status, completed_on (Y-m-d atau null)].
 *
 * @return array<int, array{0: string, 1: ?string}>
 */
function tahfidzProgress(Student $student): array
{
    return DB::table('student_juz_progress')->where('student_id', $student->id)->orderBy('juz')->get()
        ->mapWithKeys(fn (object $row): array => [(int) $row->juz => [$row->status, $row->completed_on === null ? null : substr((string) $row->completed_on, 0, 10)]])
        ->all();
}

function tahfidzRefresh(Student $student): void
{
    app(RefreshJuzProgress::class)->handle($student);
}

/**
 * Pastikan callback melempar ValidationException dengan error pada salah satu field `$fields`.
 *
 * @param  string|list<string>  $fields
 */
function tahfidzRejected(Closure $callback, string|array $fields): void
{
    $fields = (array) $fields;

    try {
        $callback();
    } catch (ValidationException $exception) {
        $keys = array_keys($exception->errors());

        expect(array_intersect($fields, $keys))->not->toBe([], 'Error pada '.implode('/', $keys).', diharapkan salah satu dari '.implode('/', $fields));

        return;
    }

    test()->fail('ValidationException diharapkan, tetapi tidak dilempar.');
}

/**
 * Kelas halaman daftar sebuah resource (apa pun namanya: List/Manage).
 *
 * @param  class-string  $resource
 * @return class-string
 */
function tahfidzIndexPage(string $resource): string
{
    return $resource::getPages()['index']->getPage();
}

/*
|--------------------------------------------------------------------------
| Helper modul Kesehatan (F031)
|--------------------------------------------------------------------------
|
| Berawalan `kesehatan`. Waktu dibekukan di 15 Okt 2026 10.00 WIB.
|
*/

/**
 * Seeder, saklar modul, tahun ajaran aktif, dan akun per peran.
 *
 * Santri: s1 SLTP penghuni Kamar A, s2 SLTP penghuni Kamar B, s3 SLTP nonmukim, s4 SLTA penghuni Kamar C.
 * `musyrif` mengawasi asrama Kamar A; `idle` pernah mengawasi tetapi penugasannya sudah berakhir.
 *
 * @return object{year: AcademicYear, sltp: Unit, slta: Unit, admin: User, kes: User, kesSltp: User, musyrif: User, idle: User, s1: Student, s2: Student, s3: Student, s4: Student, roomA: Room, roomB: Room, roomC: Room}
 */
function kesehatanFixture(): object
{
    $f = tahfidzBoot();
    Setting::set('modules.kesehatan.enabled', true);
    Setting::set('modules.asrama.enabled', true);

    $f->admin = asramaUser('super-admin');
    $f->kes = asramaUser('kesantrian');
    $f->kesSltp = asramaUser('kesantrian', ['SLTP']);

    $f->roomA = asramaRoom();
    $f->roomB = asramaRoom();
    $f->roomC = asramaRoom();

    $f->s1 = tahfidzStudent($f, 'L', null, ['name' => 'Santri Satu']);
    $f->s2 = tahfidzStudent($f, 'L', null, ['name' => 'Santri Dua']);
    $f->s3 = tahfidzStudent($f, 'L', null, ['name' => 'Santri Tiga']);
    $f->s4 = tahfidzStudent($f, 'L', $f->slta, ['name' => 'Santri Empat']);
    asramaResident($f->s1, $f->roomA);
    asramaResident($f->s2, $f->roomB);
    asramaResident($f->s4, $f->roomC);

    $f->musyrif = asramaUser('musyrif');
    $staff = Staff::factory()->create(['user_id' => $f->musyrif->id, 'gender' => 'L', 'position' => 'musyrif']);
    DormitorySupervisor::factory()->create([
        'dormitory_id' => $f->roomA->dormitory_id, 'room_id' => null, 'staff_id' => $staff->id, 'academic_year_id' => $f->year->id,
    ]);

    $f->idle = asramaUser('musyrif');
    $idleStaff = Staff::factory()->create(['user_id' => $f->idle->id, 'gender' => 'L', 'position' => 'musyrif']);
    DormitorySupervisor::factory()->ended()->create([
        'dormitory_id' => $f->roomA->dormitory_id, 'room_id' => null, 'staff_id' => $idleStaff->id, 'academic_year_id' => $f->year->id,
    ]);

    return $f;
}

/**
 * Data valid untuk `RecordHealthVisit` (santri `s1`, kemarin sore).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function kesehatanData(object $f, array $overrides = []): array
{
    return [
        'student_id' => $f->s1->id,
        'visited_at' => '2026-10-14 16:30',
        'complaint' => 'Demam dan sakit kepala sejak semalam',
        'treatment' => 'Istirahat dan parasetamol',
        'note' => 'Dipantau musyrif malam ini',
        'needs_referral' => false,
        'needs_leave' => false,
        ...$overrides,
    ];
}

/**
 * Catatan langsung lewat model (tanpa Action dan tanpa activity log).
 *
 * @param  array<string, mixed>  $attributes
 */
function kesehatanRaw(Student $student, array $attributes = []): HealthVisit
{
    return HealthVisit::query()->forceCreate([
        'student_id' => $student->id,
        'visited_at' => '2026-10-14 16:30',
        'complaint' => 'Batuk pilek',
        'treatment' => null,
        'note' => null,
        'needs_referral' => false,
        'needs_leave' => false,
        'recorded_by' => null,
        ...$attributes,
    ]);
}

/**
 * Catat lewat Action sebagai `$actor` (user ikut login karena Action memakai Gate).
 *
 * @param  array<string, mixed>  $overrides
 */
function kesehatanRecord(object $f, User $actor, array $overrides = []): HealthVisit
{
    test()->actingAs($actor);

    return app(RecordHealthVisit::class)->handle($actor, kesehatanData($f, $overrides));
}

/**
 * @return Collection<int, Activity>
 */
function kesehatanActivities(?string $event = null): Collection
{
    return Activity::query()
        ->where('log_name', 'kesehatan')
        ->when($event, fn ($query) => $query->where('event', $event))
        ->orderBy('id')
        ->get();
}

/**
 * Select santri pada form catat (opsi statis ditambah hasil pencarian `$search`).
 *
 * @return list<int>
 */
function kesehatanStudentOptions(Testable $component, string $search = ''): array
{
    $select = collect($component->instance()->form->getFlatComponents(withHidden: true))
        ->first(fn ($child, $key): bool => $child instanceof Select && str_ends_with((string) $key, 'student_id'));

    expect($select)->toBeInstanceOf(Select::class, 'Select student_id tidak ditemukan');

    $options = $select->getOptions();

    try {
        $options += $select->getSearchResults($search);
    } catch (Throwable) {
    }

    return array_map('intval', array_keys($options));
}

/*
|--------------------------------------------------------------------------
| Helper modul Perizinan (F024)
|--------------------------------------------------------------------------
|
| Berawalan `perizinan`. Waktu dibekukan di 15 Okt 2026 10.00 WIB (lewat `tahfidzBoot`).
|
*/

/**
 * User musyrif/kepala asrama beserta baris staff dan penugasan aktif (atau sudah berakhir) di kamar atau asrama.
 * Peran Spatie bawaan `musyrif` (punya `approve:leave-request`), terlepas dari `$assignmentRole`.
 */
function perizinanSupervisor(object $f, Room|Dormitory $scope, string $assignmentRole = 'musyrif', bool $ended = false): User
{
    $user = asramaUser('musyrif');
    $staff = Staff::factory()->create(['user_id' => $user->id, 'gender' => 'L', 'position' => 'musyrif']);

    $attributes = [
        'staff_id' => $staff->id,
        'academic_year_id' => $f->year->id,
        'role' => $assignmentRole,
        ...($scope instanceof Room
            ? ['dormitory_id' => $scope->dormitory_id, 'room_id' => $scope->id]
            : ['dormitory_id' => $scope->id, 'room_id' => null]),
    ];

    $factory = DormitorySupervisor::factory();
    ($ended ? $factory->ended() : $factory)->create($attributes);

    return User::query()->findOrFail($user->id);
}

/**
 * Seeder, saklar modul, jenis izin bawaan, dan akun per peran.
 *
 * Asrama `dorm1` berisi Kamar A dan B, asrama `dorm2` berisi Kamar C.
 * Santri: s1 SLTP penghuni Kamar A, s2 SLTP penghuni Kamar B, s3 SLTP nonmukim, s4 SLTA penghuni Kamar C.
 * Pengawas: `mA` musyrif Kamar A, `mDorm` musyrif tingkat asrama dorm1, `mB` musyrif Kamar B,
 * `mOther` musyrif tingkat asrama dorm2, `head` kepala asrama dorm1, `idle` musyrif dorm1 yang penugasannya sudah berakhir.
 *
 * @return object{year: AcademicYear, sltp: Unit, slta: Unit, dorm1: Dormitory, dorm2: Dormitory, roomA: Room, roomB: Room, roomC: Room, admin: User, kes: User, kesSltp: User, bendahara: User, pos: User, mA: User, mDorm: User, mB: User, mOther: User, head: User, idle: User, s1: Student, s2: Student, s3: Student, s4: Student, keluar: LeaveType, pulang: LeaveType, sakit: LeaveType, keperluan: LeaveType}
 */
function perizinanFixture(): object
{
    $f = tahfidzBoot();
    Setting::set('modules.perizinan.enabled', true);
    Setting::set('modules.asrama.enabled', true);
    test()->seed(LeaveTypeSeeder::class);

    foreach (['keluar' => 'KELUAR', 'pulang' => 'PULANG', 'sakit' => 'SAKIT', 'keperluan' => 'KEPERLUAN'] as $key => $code) {
        $f->{$key} = LeaveType::query()->where('code', $code)->firstOrFail();
    }

    $f->dorm1 = asramaDormitory();
    $f->dorm2 = asramaDormitory();
    $f->roomA = asramaRoom(10, 'L', [], $f->dorm1);
    $f->roomB = asramaRoom(10, 'L', [], $f->dorm1);
    $f->roomC = asramaRoom(10, 'L', [], $f->dorm2);

    $f->admin = asramaUser('super-admin');
    $f->kes = asramaUser('kesantrian');
    $f->kesSltp = asramaUser('kesantrian', ['SLTP']);
    $f->bendahara = asramaUser('bendahara');
    $f->pos = asramaUser('petugas-pos');

    $f->s1 = tahfidzStudent($f, 'L', null, ['name' => 'Santri Satu']);
    $f->s2 = tahfidzStudent($f, 'L', null, ['name' => 'Santri Dua']);
    $f->s3 = tahfidzStudent($f, 'L', null, ['name' => 'Santri Tiga']);
    $f->s4 = tahfidzStudent($f, 'L', $f->slta, ['name' => 'Santri Empat']);
    asramaResident($f->s1, $f->roomA);
    asramaResident($f->s2, $f->roomB);
    asramaResident($f->s4, $f->roomC);

    $f->mA = perizinanSupervisor($f, $f->roomA);
    $f->mDorm = perizinanSupervisor($f, $f->dorm1);
    $f->mB = perizinanSupervisor($f, $f->roomB);
    $f->mOther = perizinanSupervisor($f, $f->dorm2);
    $f->head = perizinanSupervisor($f, $f->dorm1, 'kepala_asrama');
    $f->idle = perizinanSupervisor($f, $f->dorm1, 'musyrif', true);

    return $f;
}

/**
 * Santri SLTP aktif penghuni `$room` (bukan santri bawaan fixture).
 *
 * @param  array<string, mixed>  $attributes
 */
function perizinanResident(object $f, ?Room $room = null, array $attributes = []): Student
{
    $student = tahfidzStudent($f, 'L', null, $attributes);
    asramaResident($student, $room ?? $f->roomA);

    return $student;
}

/**
 * Data valid untuk `SubmitLeaveRequest`: izin KELUAR santri `s1`, empat jam siang ini.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function perizinanData(object $f, array $overrides = []): array
{
    return [
        'student_id' => $f->s1->id,
        'leave_type_id' => $f->keluar->id,
        'requested_via' => 'musyrif',
        'reason' => 'Berobat ke dokter gigi',
        'planned_departure' => '2026-10-15 13:00',
        'return_due' => '2026-10-15 17:00',
        'pickup_name' => null,
        'pickup_phone' => null,
        ...$overrides,
    ];
}

/**
 * Ajukan izin lewat Action sebagai `$actor` (user ikut login karena cakupan data memakai user login).
 *
 * @param  array<string, mixed>  $overrides
 */
function perizinanSubmit(object $f, User $actor, array $overrides = []): LeaveRequest
{
    test()->actingAs($actor);

    return app(SubmitLeaveRequest::class)->handle($actor, perizinanData($f, $overrides));
}

/**
 * Izin langsung lewat model (tanpa Action dan tanpa activity log). Kolom waktu mengikuti `$status`.
 *
 * @param  array<string, mixed>  $attributes
 */
function perizinanRaw(Student $student, LeaveType $type, string $status = 'diajukan', array $attributes = []): LeaveRequest
{
    $decided = in_array($status, ['disetujui', 'ditolak', 'keluar', 'kembali'], true);

    return LeaveRequest::query()->forceCreate([
        'student_id' => $student->id,
        'leave_type_id' => $type->id,
        'homecoming_period_id' => null,
        'requested_via' => 'musyrif',
        'requested_by' => null,
        'reason' => 'Keperluan keluarga',
        'planned_departure' => '2026-10-15 13:00',
        'return_due' => '2026-10-15 17:00',
        'status' => $status,
        'approved_at' => $decided ? '2026-10-15 08:00' : null,
        'departed_at' => in_array($status, ['keluar', 'kembali'], true) ? '2026-10-15 09:00' : null,
        'returned_at' => $status === 'kembali' ? '2026-10-15 09:30' : null,
        ...$attributes,
    ]);
}

/**
 * Baris izin terbaru tanpa cakupan apa pun (untuk assert, bukan untuk menguji cakupan).
 */
function perizinanFresh(LeaveRequest $request): LeaveRequest
{
    return LeaveRequest::withoutGlobalScopes()->findOrFail($request->id);
}

/**
 * Nilai string sebuah kolom status/enum, baik disimpan sebagai string maupun enum.
 */
function perizinanValue(mixed $value): mixed
{
    return $value instanceof BackedEnum ? $value->value : $value;
}

/**
 * Jumlah semua izin tanpa cakupan apa pun.
 */
function perizinanCount(): int
{
    return LeaveRequest::withoutGlobalScopes()->count();
}

/**
 * @return Collection<int, Activity>
 */
function perizinanActivities(?string $event = null): Collection
{
    return Activity::query()
        ->where('log_name', 'perizinan')
        ->when($event, fn ($query) => $query->where('event', $event))
        ->orderBy('id')
        ->get();
}

/**
 * Pastikan callback ditolak, baik karena validasi maupun karena otorisasi.
 */
function perizinanDenied(Closure $callback): void
{
    try {
        $callback();
    } catch (ValidationException|AuthorizationException) {
        return;
    }

    test()->fail('ValidationException atau AuthorizationException diharapkan, tetapi tidak dilempar.');
}

/**
 * Periode libur langsung lewat model (default 20-27 Okt 2026).
 *
 * @param  array<string, mixed>  $attributes
 */
function perizinanPeriod(object $f, array $attributes = []): HomecomingPeriod
{
    return HomecomingPeriod::query()->forceCreate([
        'academic_year_id' => $f->year->id,
        'name' => 'Libur Semester Ganjil',
        'depart_on' => '2026-10-20',
        'return_on' => '2026-10-27',
        ...$attributes,
    ]);
}

/**
 * Data valid untuk `RecordVisit`: ayah santri `s1` berkunjung pukul 09.00 hari ini.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function perizinanVisitData(object $f, array $overrides = []): array
{
    return [
        'student_id' => $f->s1->id,
        'guardian_id' => null,
        'visitor_name' => 'Bapak Ahmad',
        'relation' => 'ayah',
        'started_at' => '2026-10-15 09:00',
        'ended_at' => null,
        'items_note' => 'Titipan makanan ringan',
        ...$overrides,
    ];
}

/**
 * Kunjungan langsung lewat model.
 *
 * @param  array<string, mixed>  $attributes
 */
function perizinanRawVisit(Student $student, array $attributes = []): Visit
{
    return Visit::query()->forceCreate([
        'student_id' => $student->id,
        'guardian_id' => null,
        'visitor_name' => 'Bapak Ahmad',
        'relation' => 'ayah',
        'started_at' => '2026-10-15 09:00',
        'ended_at' => null,
        'items_note' => null,
        'recorded_by' => null,
        ...$attributes,
    ]);
}

/**
 * Opsi sebuah Select pada form (opsi statis ditambah hasil pencarian `$search`).
 *
 * @return list<int>
 */
function perizinanSelectOptions(Testable $component, string $field, string $search = ''): array
{
    $select = collect($component->instance()->form->getFlatComponents(withHidden: true))
        ->first(fn ($child, $key): bool => $child instanceof Select && str_ends_with((string) $key, $field));

    expect($select)->toBeInstanceOf(Select::class, "Select {$field} tidak ditemukan");

    $options = $select->getOptions();

    try {
        $options += $select->getSearchResults($search);
    } catch (Throwable) {
    }

    return array_map('intval', array_keys($options));
}

/*
|--------------------------------------------------------------------------
| Helper modul Absensi (F025)
|--------------------------------------------------------------------------
|
| Berawalan `absensi`. Waktu dibekukan di 15 Okt 2026 10.00 WIB, tahun ajaran aktif 2026/2027 (1 Jul 2026 - 30 Jun 2027).
|
*/

/**
 * Seeder, saklar modul, rombel, akun per peran, dan santri.
 *
 * Rombel: `c7a` (SLTP, wali = staff `homeroomStaff`), `c7b` (SLTP, tanpa wali), `c10a` (SLTA).
 * Santri 7A: `s1`, `s2`, `s3` aktif dan `grad` (status lulus, tetap terdaftar di 7A); `o7b` di 7B; `o10` di 10A.
 * Akun: `admin` admin-sekolah semua jenjang, `adminSltp` admin-sekolah SLTP, `kes` kesantrian semua jenjang,
 * `kesSltp` kesantrian SLTP, `homeroom` wali-kelas wali 7A, `idle` wali-kelas berstaff tetapi bukan wali rombel mana pun,
 * `noStaff` wali-kelas tanpa baris staff.
 *
 * @return object{year: AcademicYear, sltp: Unit, slta: Unit, c7a: Classroom, c7b: Classroom, c10a: Classroom, homeroomStaff: Staff, admin: User, adminSltp: User, kes: User, kesSltp: User, homeroom: User, idle: User, noStaff: User, s1: Student, s2: Student, s3: Student, grad: Student, o7b: Student, o10: Student}
 */
function absensiFixture(): object
{
    $f = tahfidzBoot();

    foreach (['asrama', 'perizinan', 'absensi'] as $module) {
        Setting::set("modules.{$module}.enabled", true);
    }

    $f->homeroomStaff = Staff::factory()->create(['gender' => 'L', 'position' => 'ustadz']);
    $f->c7a = Classroom::factory()->create(['academic_year_id' => $f->year->id, 'unit_id' => $f->sltp->id, 'grade' => 7, 'name' => '7A', 'homeroom_staff_id' => $f->homeroomStaff->id]);
    $f->c7b = Classroom::factory()->create(['academic_year_id' => $f->year->id, 'unit_id' => $f->sltp->id, 'grade' => 7, 'name' => '7B']);
    $f->c10a = Classroom::factory()->create(['academic_year_id' => $f->year->id, 'unit_id' => $f->slta->id, 'grade' => 10, 'name' => '10A']);

    $f->admin = asramaUser('admin-sekolah');
    $f->adminSltp = asramaUser('admin-sekolah', ['SLTP']);
    $f->kes = asramaUser('kesantrian');
    $f->kesSltp = asramaUser('kesantrian', ['SLTP']);
    $f->homeroom = asramaUser('wali-kelas');
    $f->homeroomStaff->forceFill(['user_id' => $f->homeroom->id])->save();
    $f->idle = asramaUser('wali-kelas');
    Staff::factory()->create(['user_id' => $f->idle->id, 'gender' => 'L', 'position' => 'ustadz']);
    $f->noStaff = asramaUser('wali-kelas');

    foreach (['s1' => 'Santri Satu', 's2' => 'Santri Dua', 's3' => 'Santri Tiga'] as $key => $name) {
        $f->{$key} = absensiStudent($f, $f->c7a, $name);
    }

    $f->grad = absensiStudent($f, $f->c7a, 'Santri Lulus', ['status' => 'lulus']);
    $f->o7b = absensiStudent($f, $f->c7b, 'Santri Tujuh B');
    $f->o10 = absensiStudent($f, $f->c10a, 'Santri Sepuluh', [], $f->slta);

    return $f;
}

/**
 * Santri yang terdaftar (enrollment) di `$classroom`.
 *
 * @param  array<string, mixed>  $attributes
 */
function absensiStudent(object $f, Classroom $classroom, string $name, array $attributes = [], ?Unit $unit = null): Student
{
    $student = tahfidzStudent($f, 'L', $unit, ['name' => $name, ...$attributes]);
    Enrollment::factory()->create(['student_id' => $student->id, 'classroom_id' => $classroom->id, 'academic_year_id' => $f->year->id]);

    return $student;
}

/**
 * Catat absensi lewat Action sebagai `$actor` (ikut login karena cakupan data memakai user login).
 *
 * @param  array<int, string>  $statuses  student_id => status
 */
function absensiRecord(User $actor, Classroom $classroom, string $date, array $statuses = []): AttendanceSession
{
    test()->actingAs($actor);

    return app(RecordClassroomAttendance::class)->handle($actor, $classroom, $date, $statuses);
}

/**
 * Sesi (dan baris) langsung lewat model tanpa Action.
 *
 * @param  array<int, string>  $statuses  student_id => status
 */
function absensiRaw(Classroom $classroom, string $date, array $statuses = [], ?User $recorder = null): AttendanceSession
{
    $session = AttendanceSession::query()->forceCreate([
        'classroom_id' => $classroom->id,
        'held_on' => $date,
        'recorded_by' => $recorder?->id,
    ]);

    foreach ($statuses as $studentId => $status) {
        AttendanceRecord::query()->forceCreate(['attendance_session_id' => $session->id, 'student_id' => $studentId, 'status' => $status]);
    }

    return $session;
}

/**
 * Status tersimpan sebuah rombel pada tanggal tertentu: student_id => status (tanpa cakupan apa pun).
 *
 * @return array<int, string>
 */
function absensiStored(Classroom $classroom, string $date): array
{
    return DB::table('attendance_records')
        ->join('attendance_sessions', 'attendance_sessions.id', '=', 'attendance_records.attendance_session_id')
        ->where('attendance_sessions.classroom_id', $classroom->id)
        ->whereDate('attendance_sessions.held_on', $date)
        ->pluck('attendance_records.status', 'attendance_records.student_id')
        ->all();
}

/**
 * Seluruh isi tabel absensi sebagai penanda perubahan (untuk assert "tidak ada yang berubah").
 *
 * @return array{sessions: list<string>, records: list<string>}
 */
function absensiSnapshot(): array
{
    return [
        'sessions' => DB::table('attendance_sessions')->orderBy('id')->get()->map(fn (object $row): string => json_encode($row))->all(),
        'records' => DB::table('attendance_records')->orderBy('id')->get()->map(fn (object $row): string => json_encode($row))->all(),
    ];
}

/**
 * Izin dari Perizinan langsung lewat model; kolom waktu mengikuti `$status` kecuali `$attributes` menimpa.
 *
 * @param  array<string, mixed>  $attributes
 */
function absensiLeave(Student $student, string $status, array $attributes = []): LeaveRequest
{
    $type = LeaveType::query()->where('name', 'Izin Berobat')->first() ?? LeaveType::factory()->create(['name' => 'Izin Berobat']);

    return perizinanRaw($student, $type, $status, $attributes);
}

/**
 * Lembar absensi hasil `BuildAttendanceSheet` pada tanggal tertentu.
 *
 * @return Collection<int, mixed>
 */
function absensiSheet(Classroom $classroom, string $date): Collection
{
    return app(BuildAttendanceSheet::class)->handle($classroom, CarbonImmutable::parse($date, 'Asia/Jakarta'));
}

/**
 * Baris lembar untuk santri tertentu: status (string), locked (bool), dan id izin petunjuk (atau null).
 * Baris boleh berupa array maupun objek.
 *
 * @param  Collection<int, mixed>  $sheet
 * @return array{status: ?string, locked: bool, leave: ?int}|null
 */
function absensiRow(Collection $sheet, Student $student): ?array
{
    $row = $sheet->first(fn (mixed $row): bool => (int) data_get($row, 'student.id') === $student->id);

    if ($row === null) {
        return null;
    }

    $status = data_get($row, 'status');

    return [
        'status' => $status instanceof BackedEnum ? $status->value : $status,
        'locked' => (bool) data_get($row, 'locked'),
        'leave' => data_get($row, 'leave.id'),
    ];
}

/**
 * Hadir/Sakit/Izin/Alpa pada baris tabel (halaman rekap) yang memuat `$name`: empat sel numerik terakhir, atau null bila baris tidak ada.
 *
 * @return list<int>|null
 */
function absensiRecapRow(Testable $component, string $name): ?array
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$component->html());
    $xpath = new DOMXPath($dom);

    foreach ($xpath->query('//tr') as $row) {
        if (! str_contains((string) $row->textContent, $name)) {
            continue;
        }

        $cells = [];

        foreach ($xpath->query('./th|./td', $row) as $cell) {
            $cells[] = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $cell->textContent));
        }

        return array_map('intval', array_slice($cells, -4));
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| Helper modul Kedisiplinan (F026)
|--------------------------------------------------------------------------
|
| Berawalan `disiplin`. Baris dibuat dengan `forceCreate` (tanpa Action dan tanpa factory) agar test
| tidak bergantung pada aturan yang sedang diuji. Waktu dibekukan di 15 Okt 2026 10.00 WIB.
|
*/

/**
 * Fixture Kesehatan (santri s1-s4, kamar, musyrif, kesantrian) ditambah saklar Kedisiplinan, akun
 * admin-sekolah/wali-kelas/bendahara, tahun ajaran sebelumnya 2025/2026, dan tiga jenis pelanggaran:
 * `ringan` (5 poin), `sedang` (25 poin), `berat` (50 poin).
 *
 * @return object{year: AcademicYear, prevYear: AcademicYear, sltp: Unit, slta: Unit, admin: User, kes: User, kesSltp: User, musyrif: User, idle: User, adminSekolah: User, waliKelas: User, bendahara: User, s1: Student, s2: Student, s3: Student, s4: Student, ringan: ViolationType, sedang: ViolationType, berat: ViolationType}
 */
function disiplinFixture(): object
{
    $f = kesehatanFixture();
    Setting::set('modules.kedisiplinan.enabled', true);

    $f->prevYear = AcademicYear::factory()->create(['name' => '2025/2026', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
    $f->adminSekolah = asramaUser('admin-sekolah');
    $f->waliKelas = asramaUser('wali-kelas');
    $f->bendahara = asramaUser('bendahara');

    $f->ringan = disiplinType('Terlambat shalat berjamaah', 'ringan', 5);
    $f->sedang = disiplinType('Keluar pondok tanpa izin', 'sedang', 25);
    $f->berat = disiplinType('Berkelahi', 'berat', 50);

    return $f;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function disiplinType(string $name, string $category = 'ringan', int $points = 5, array $attributes = []): ViolationType
{
    return ViolationType::query()->forceCreate([
        'name' => $name, 'category' => $category, 'points' => $points, 'is_active' => true, ...$attributes,
    ]);
}

function disiplinLevel(string $name, int $minPoints): DisciplineLevel
{
    return DisciplineLevel::query()->forceCreate(['name' => $name, 'min_points' => $minPoints]);
}

/**
 * Data valid untuk `RecordStudentViolation` (santri `s1`, jenis `ringan`, kemarin).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function disiplinData(object $f, array $overrides = []): array
{
    return [
        'student_id' => $f->s1->id,
        'violation_type_id' => $f->ringan->id,
        'occurred_on' => '2026-10-14',
        'description' => 'Terlambat ke masjid saat shalat subuh',
        ...$overrides,
    ];
}

/**
 * Catatan langsung lewat model (tahun ajaran aktif, poin = poin jenis kecuali ditimpa).
 *
 * @param  array<string, mixed>  $attributes
 */
function disiplinRaw(object $f, Student $student, ViolationType $type, array $attributes = []): StudentViolation
{
    return StudentViolation::query()->forceCreate([
        'student_id' => $student->id,
        'violation_type_id' => $type->id,
        'academic_year_id' => $f->year->id,
        'occurred_on' => '2026-10-10',
        'points' => $type->points,
        'description' => null,
        'recorded_by' => null,
        'cancelled_at' => null,
        'cancelled_by' => null,
        'cancel_reason' => null,
        ...$attributes,
    ]);
}

/**
 * Catatan yang sudah dibatalkan, langsung lewat model.
 *
 * @param  array<string, mixed>  $attributes
 */
function disiplinRawCancelled(object $f, Student $student, ViolationType $type, array $attributes = []): StudentViolation
{
    return disiplinRaw($f, $student, $type, [
        'cancelled_at' => now(), 'cancelled_by' => $f->kes->id, 'cancel_reason' => 'Salah catat', ...$attributes,
    ]);
}

/**
 * Catat lewat Action sebagai `$actor` (ikut login karena cakupan data memakai user login).
 *
 * @param  array<string, mixed>  $overrides
 */
function disiplinRecord(object $f, User $actor, array $overrides = []): StudentViolation
{
    test()->actingAs($actor);

    return app(RecordStudentViolation::class)->handle($actor, disiplinData($f, $overrides));
}

/**
 * Semua catatan tanpa global scope (untuk menghitung baris apa pun yang login).
 */
function disiplinCount(): int
{
    return StudentViolation::withoutGlobalScopes()->count();
}

/**
 * @return Collection<int, Activity>
 */
function disiplinActivities(?string $event = null): Collection
{
    return Activity::query()
        ->where('log_name', 'kedisiplinan')
        ->when($event, fn ($query) => $query->where('event', $event))
        ->orderBy('id')
        ->get();
}

/**
 * Pilihan sebuah Select (`$field`) pada form Livewire, ditambah hasil pencarian `$search`.
 *
 * @return list<int>
 */
function disiplinOptions(Testable $component, string $field, string $search = ''): array
{
    $select = collect($component->instance()->form->getFlatComponents(withHidden: true))
        ->first(fn ($child, $key): bool => $child instanceof Select && str_ends_with((string) $key, $field));

    expect($select)->toBeInstanceOf(Select::class, "Select {$field} tidak ditemukan");

    $options = $select->getOptions();

    try {
        $options += $select->getSearchResults($search);
    } catch (Throwable) {
    }

    return array_map('intval', array_keys($options));
}

/*
|--------------------------------------------------------------------------
| Helper uang saku santri (F028)
|--------------------------------------------------------------------------
|
| Berawalan `wallet`. Test pemanggil menyeding `UnitSeeder` dan `RolePermissionSeeder`.
|
*/

/**
 * Santri pada jenjang `$unitCode` (kode unit seeder: SD, SLTP, SLTA).
 *
 * @param  array<string, mixed>  $attributes
 */
function walletStudent(string $unitCode = 'SLTP', array $attributes = []): Student
{
    $unit = Unit::query()->where('code', $unitCode)->firstOrFail();

    return Student::factory()->forCohort(Cohort::factory()->create(['unit_id' => $unit->id]))->create($attributes);
}

/**
 * Catat transaksi lewat Action (tanggal bawaan hari ini).
 *
 * @param  array<string, mixed>  $extra
 */
function walletRecord(Student $student, User $user, string $type, mixed $amount, array $extra = []): WalletTransaction
{
    return app(RecordWalletTransaction::class)->handle($student, $user, [
        'type' => $type,
        'amount' => $amount,
        'occurred_on' => today('Asia/Jakarta')->toDateString(),
        ...$extra,
    ]);
}

function walletBalance(Student $student): int
{
    return WalletBalance::forStudent($student->id);
}

/**
 * Pastikan callback melempar ValidationException dengan pesan `pondok.wallet.errors.<reason>`.
 * Bila `$field` diisi, error harus ada pada field tersebut.
 */
function walletAssertRejected(Closure $callback, string $reason, ?string $field = null): void
{
    $message = __("pondok.wallet.errors.{$reason}");
    expect($message)->not->toBe("pondok.wallet.errors.{$reason}");

    $parts = preg_split('/:[A-Za-z_]+/', $message);
    $pattern = '/^'.implode('.+', array_map(fn (string $part): string => preg_quote($part, '/'), $parts)).'$/su';

    try {
        $callback();
    } catch (ValidationException $exception) {
        $errors = $exception->errors();

        if ($field !== null) {
            expect($errors)->toHaveKey($field);
        }

        $matches = collect($errors)->flatten()->contains(fn (string $text): bool => preg_match($pattern, $text) === 1);

        expect($matches)->toBeTrue("Pesan error tidak sesuai pondok.wallet.errors.{$reason}: ".json_encode($errors));

        return;
    }

    test()->fail('ValidationException diharapkan, tetapi tidak dilempar.');
}
