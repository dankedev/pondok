<?php

use App\Actions\Keuangan\CancelPayment;
use App\Actions\Keuangan\FinanceReportFilter;
use App\Actions\Keuangan\GenerateMonthlyInvoices;
use App\Actions\Keuangan\RecordPayment;
use App\Models\AcademicYear;
use App\Models\Cohort;
use App\Models\Dormitory;
use App\Models\FeeOverride;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Scholarship;
use App\Models\Student;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\UnitSeeder;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
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
