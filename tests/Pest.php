<?php

use App\Actions\Keuangan\CancelPayment;
use App\Actions\Keuangan\FinanceReportFilter;
use App\Actions\Keuangan\GenerateInvoiceNumber;
use App\Actions\Keuangan\GenerateMonthlyInvoices;
use App\Actions\Keuangan\RecordPayment;
use App\Actions\Psb\GenerateApplicantNumber;
use App\Actions\Psb\GenerateRegistrationInvoices;
use App\Actions\Psb\SubmitApplication;
use App\Filament\Resources\Applicants\Pages\ViewApplicant;
use App\Models\AcademicYear;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionTrack;
use App\Models\AdmissionWave;
use App\Models\Album;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\Category;
use App\Models\Cohort;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Dormitory;
use App\Models\FeeOverride;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Photo;
use App\Models\Post;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Scholarship;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Unit;
use App\Models\User;
use App\Models\Video;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\AdmissionTrackSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\StaticPageSeeder;
use Database\Seeders\UnitSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
    $tahfidz = laporanScholarship('Beasiswa Tahfidz', 'Yayasan Al-Khair');
    $yatim = laporanScholarship('Yatim', 'Yayasan Al-Khair');
    $ustadz = laporanScholarship('Anak Ustadz');
    laporanScholarship('Program Kosong', 'Yayasan Al-Khair');

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
 * Tepat tiga saklar modul aktif (akademik, keuangan, konten) dari sembilan.
 */
function dashboardEnableThreeModules(): void
{
    foreach (SettingSeeder::MODULES as $module) {
        Setting::set("modules.{$module}.enabled", in_array($module, ['akademik', 'keuangan', 'konten'], true));
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
        'type' => 'berita',
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
        'type' => 'berita',
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
