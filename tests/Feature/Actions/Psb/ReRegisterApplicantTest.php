<?php

use App\Actions\Akademik\GenerateStudentNumber;
use App\Actions\Keuangan\ResolveFeeOverride;
use App\Actions\Psb\CopyApplicantDocuments;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\Cohort;
use App\Models\Document;
use App\Models\FeeOverride;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->f = daftarUlangFixture();
});

/**
 * P lengkap untuk AC-9: tagihan formulir lunas + formulir `batal` + penerimaan (dibayar 4.000.000), keringanan SERAGAM
 * berakhir kemarin dan keringanan jalur SPP persen 50 (tanpa akhir), berkas KK/AKTA `valid` dan RAPOR `menunggu`.
 *
 * @return object{applicant: Applicant, expired: FeeOverride, track: FeeOverride, rapor: ApplicantDocument, cancelled: Invoice}
 */
function daftarUlangRich(object $f): object
{
    $applicant = daftarUlangApplicant($f);

    return (object) [
        'applicant' => $applicant,
        'expired' => FeeOverride::factory()->forApplicant($applicant->id)->discount(100000)->between('2026-09-01', '2026-10-14')->create(['fee_type_id' => $f->seragam->id]),
        'track' => FeeOverride::factory()->forApplicant($applicant->id)->percent(50)->between('2026-10-15', null)->create(['fee_type_id' => $f->spp->id]),
        'rapor' => daftarUlangValidDocument($applicant, 'RAPOR', 'menunggu'),
        'cancelled' => Invoice::factory()->cancelled()->create(['student_id' => null, 'applicant_id' => $applicant->id, 'academic_year_id' => $f->y2027->id, 'billing_group' => 'PSB_DAFTAR']),
    ];
}

/**
 * Isi keringanan yang tidak boleh berubah saat dipindahkan (tanggal sebagai `Y-m-d`, tanpa `student_id`).
 *
 * @return array<string, mixed>
 */
function daftarUlangOverrideRow(FeeOverride $override): array
{
    return [
        ...$override->only(['id', 'applicant_id', 'fee_type_id', 'mode', 'value', 'reason']),
        'valid_from' => $override->valid_from->toDateString(),
        'valid_until' => $override->valid_until?->toDateString(),
    ];
}

/**
 * Salinan `CopyApplicantDocuments` yang menyalin lewat instance asli lalu melempar RuntimeException `gagal setelah salin`.
 */
function daftarUlangFailingCopier(): object
{
    return daftarUlangBind(CopyApplicantDocuments::class, new class(app(CopyApplicantDocuments::class)) extends CopyApplicantDocuments
    {
        public function __construct(private CopyApplicantDocuments $real) {}

        public function handle(Applicant $applicant, Student $student, Guardian $guardian, User $by, array &$copiedPaths): array
        {
            $this->real->handle($applicant, $student, $guardian, $by, $copiedPaths);

            throw new RuntimeException('gagal setelah salin');
        }
    });
}

/**
 * Salinan `GenerateStudentNumber`: mengembalikan `$taken` pada panggilan pertama (atau selalu bila `$always`), selain itu
 * meneruskan ke instance asli. `$onFirstCall` dijalankan sebelum panggilan pertama.
 */
function daftarUlangFakeStudentNumber(?string $taken = null, bool $always = false, ?Closure $onFirstCall = null): object
{
    return daftarUlangBind(GenerateStudentNumber::class, new class(app(GenerateStudentNumber::class), $taken, $always, $onFirstCall) extends GenerateStudentNumber
    {
        public int $calls = 0;

        public function __construct(private GenerateStudentNumber $real, private ?string $taken, private bool $always, private ?Closure $onFirstCall) {}

        public function handle(Unit $unit, int $entryYear): string
        {
            if (++$this->calls === 1 && $this->onFirstCall !== null) {
                ($this->onFirstCall)();
            }

            if ($this->taken !== null && ($this->always || $this->calls === 1)) {
                return $this->taken;
            }

            return $this->real->handle($unit, $entryYear);
        }
    });
}

it('AC-9: converts an applicant with invoices, overrides, and documents', function () {
    $rich = daftarUlangRich($this->f);
    $applicant = $rich->applicant;
    $billing = daftarUlangBilling($applicant);
    $overridesBefore = FeeOverride::query()->where('applicant_id', $applicant->id)->orderBy('id')->get()->map(fn (FeeOverride $override): array => daftarUlangOverrideRow($override))->all();
    $sourceFiles = Storage::disk('private')->allFiles();

    $result = daftarUlangRun($applicant, ['relation' => 'ayah']);
    $student = Student::withoutGlobalScopes()->where('applicant_id', $applicant->id)->sole();

    expect($result)->toMatchArray(['invoices' => 3, 'fee_overrides' => 2, 'documents_copied' => 2])
        ->and($result['student']->is($student))->toBeTrue()
        ->and($student)->unit_id->toBe($this->f->sltp->id)->name->toBe('Ahmad Fauzan')->gender->toBe('L')->birth_place->toBe('Bandung')
        ->nisn->toBe('0123456789')->status->toBe('aktif')->address->toBeNull()
        ->and($student->birth_date->toDateString())->toBe('2014-05-12')
        ->and(Student::withoutGlobalScopes()->count())->toBe(1);

    $invoices = Invoice::withoutUnitScope()->where('applicant_id', $applicant->id)->orderBy('id')->get();

    expect($invoices)->toHaveCount(3)
        ->and($invoices->pluck('student_id')->unique()->all())->toBe([$student->id])
        ->and($invoices->pluck('applicant_id')->unique()->all())->toBe([$applicant->id])
        ->and(daftarUlangBilling($applicant))->toBe($billing)
        ->and($invoices->firstWhere('id', $rich->cancelled->id)->status)->toBe('batal');

    $overrides = FeeOverride::query()->where('applicant_id', $applicant->id)->orderBy('id')->get();

    expect($overrides)->toHaveCount(2)
        ->and($overrides->pluck('student_id')->unique()->all())->toBe([$student->id])
        ->and($overrides->map(fn (FeeOverride $override): array => daftarUlangOverrideRow($override))->all())
        ->toBe($overridesBefore);

    $documents = Document::withoutUnitScope()->where('documentable_type', 'student')->where('documentable_id', $student->id)->get();

    expect($documents)->toHaveCount(2)
        ->and($documents->pluck('documentType.code')->sort()->values()->all())->toBe(['AKTA', 'KK'])
        ->and(Document::withoutUnitScope()->where('source_applicant_document_id', $rich->rapor->id)->exists())->toBeFalse()
        ->and(Storage::disk('private')->allFiles())->toHaveCount(count($sourceFiles) + 2);
});

it('AC-10: leaves other applicants untouched', function () {
    $applicant = daftarUlangApplicant($this->f);
    $other = daftarUlangApplicant($this->f);
    FeeOverride::factory()->forApplicant($other->id)->percent(25)->between('2026-10-15', null)->create(['fee_type_id' => $this->f->spp->id]);
    $otherDocuments = ApplicantDocument::withoutGlobalScopes()->where('applicant_id', $other->id)->orderBy('id')->get(['id', 'status', 'file_path'])->toArray();
    $billing = daftarUlangBilling($other);
    $overrides = FeeOverride::query()->where('applicant_id', $other->id)->orderBy('id')->get(['id', 'student_id', 'applicant_id'])->toArray();

    daftarUlangRun($applicant);

    expect(daftarUlangBilling($other))->toBe($billing)
        ->and(Invoice::withoutUnitScope()->where('applicant_id', $other->id)->whereNotNull('student_id')->exists())->toBeFalse()
        ->and(FeeOverride::query()->where('applicant_id', $other->id)->orderBy('id')->get(['id', 'student_id', 'applicant_id'])->toArray())->toBe($overrides)
        ->and(ApplicantDocument::withoutGlobalScopes()->where('applicant_id', $other->id)->orderBy('id')->get(['id', 'status', 'file_path'])->toArray())->toBe($otherDocuments)
        ->and(Document::withoutUnitScope()->count())->toBe(2)
        ->and(Student::withoutGlobalScopes()->where('applicant_id', $other->id)->exists())->toBeFalse();
});

it('AC-11: makes moved billing visible to the student', function () {
    Filament::setCurrentPanel('admin');
    $rich = daftarUlangRich($this->f);
    $student = daftarUlangRun($rich->applicant)['student'];

    $override = app(ResolveFeeOverride::class)->forStudent($student, $this->f->spp, CarbonImmutable::parse('2027-07-01', 'Asia/Jakarta'));

    expect($override?->id)->toBe($rich->track->id)
        ->and($student->invoices()->count())->toBe(3);

    $acceptance = seleksiInvoices($rich->applicant, group: 'PSB_TERIMA')->sole();
    $this->actingAs(psbUser('bendahara'));

    Livewire::test(ListInvoices::class)
        ->assertCanSeeTableRecords([$acceptance])
        ->assertSee($student->nis);
});

it('AC-25: refuses a second conversion', function () {
    $applicant = daftarUlangApplicant($this->f);
    $stale = Applicant::withoutGlobalScopes()->findOrFail($applicant->id);
    $first = daftarUlangRun($applicant);
    $after = daftarUlangSnapshot();

    daftarUlangAssertRejected(fn () => daftarUlangRun($stale), 'already_converted', 'applicant');
    daftarUlangAssertRejected(fn () => daftarUlangRun($applicant), 'already_converted', 'applicant');

    expect(daftarUlangSnapshot())->toBe($after);

    $first['student']->delete();
    $afterDelete = daftarUlangSnapshot();

    daftarUlangAssertRejected(fn () => daftarUlangRun($applicant), 'already_converted', 'applicant');

    expect(daftarUlangSnapshot())->toBe($afterDelete)->and(Student::withoutGlobalScopes()->count())->toBe(1);
});

it('AC-26: requires an accepted applicant', function (string $status) {
    $applicant = daftarUlangApplicant($this->f);
    Applicant::withoutGlobalScopes()->whereKey($applicant->id)->update(['status' => $status]);
    $before = daftarUlangSnapshot();

    daftarUlangAssertRejected(fn () => daftarUlangRun($applicant), 'applicant_not_accepted', 'status');

    expect(daftarUlangSnapshot())->toBe($before);
})->with(['diajukan', 'diverifikasi', 'cadangan', 'ditolak']);

it('AC-26: refuses a deleted applicant', function () {
    $applicant = daftarUlangApplicant($this->f);
    $stale = Applicant::withoutGlobalScopes()->findOrFail($applicant->id);
    $applicant->delete();
    $before = daftarUlangSnapshot();

    daftarUlangAssertRejected(fn () => daftarUlangRun($stale), 'applicant_not_found', 'applicant');

    expect(daftarUlangSnapshot())->toBe($before);
});

it('AC-27: rolls back and removes copied files on failure', function () {
    $rich = daftarUlangRich($this->f);
    $before = daftarUlangSnapshot();
    daftarUlangFailingCopier();

    expect(fn () => daftarUlangRun($rich->applicant))->toThrow(RuntimeException::class, 'gagal setelah salin');

    expect(daftarUlangSnapshot())->toBe($before)
        ->and(Student::withoutGlobalScopes()->count())->toBe(0)
        ->and(Cohort::query()->where('entry_year', 2027)->exists())->toBeFalse()
        ->and(Invoice::withoutUnitScope()->where('applicant_id', $rich->applicant->id)->whereNotNull('student_id')->exists())->toBeFalse()
        ->and(FeeOverride::query()->where('applicant_id', $rich->applicant->id)->whereNotNull('student_id')->exists())->toBeFalse()
        ->and(daftarUlangActivities('psb', 'converted'))->toHaveCount(0)
        ->and(daftarUlangActivities('keuangan', 'applicant_billing_transferred'))->toHaveCount(0)
        ->and(daftarUlangActivities('dokumen', 'copied_from_applicant'))->toHaveCount(0);
});

it('AC-27: keeps the original exception when deleting a compensation file fails', function () {
    $rich = daftarUlangRich($this->f);
    daftarUlangFailingCopier();
    $disk = Mockery::mock(Storage::disk('private'));
    $disk->shouldReceive('delete')->andThrow(new RuntimeException('disk gagal hapus'));
    Storage::set('private', $disk);
    Log::spy();

    expect(fn () => daftarUlangRun($rich->applicant))->toThrow(RuntimeException::class, 'gagal setelah salin');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => str_contains(json_encode([$message, $context], JSON_UNESCAPED_SLASHES), 'documents/student/'))->atLeast()->once();
    expect(Student::withoutGlobalScopes()->count())->toBe(0);
});

it('AC-28: retries a colliding student number', function () {
    $rich = daftarUlangRich($this->f);
    Student::factory()->forCohort(Cohort::factory()->create(['unit_id' => $this->f->sltp->id, 'entry_year' => 2025]))->create(['nis' => '20270001']);
    $filesBefore = count(Storage::disk('private')->allFiles());
    $fake = daftarUlangFakeStudentNumber('20270001');

    $result = daftarUlangRun($rich->applicant);

    expect($result['student']->nis)->toBe('20270002')
        ->and($fake->calls)->toBe(2)
        ->and(Student::withoutGlobalScopes()->where('applicant_id', $rich->applicant->id)->count())->toBe(1)
        ->and(Storage::disk('private')->allFiles())->toHaveCount($filesBefore + 2);
});

it('AC-28: gives up after three colliding student numbers', function () {
    $rich = daftarUlangRich($this->f);
    Student::factory()->forCohort(Cohort::factory()->create(['unit_id' => $this->f->sltp->id, 'entry_year' => 2025]))->create(['nis' => '20270001']);
    $before = daftarUlangSnapshot();
    $fake = daftarUlangFakeStudentNumber('20270001', always: true);
    Log::spy();

    expect(fn () => daftarUlangRun($rich->applicant))->toThrow(RuntimeException::class, __('pondok.psb.errors.student_number_conflict'));

    Log::shouldHaveReceived('error')->atLeast()->once();
    expect($fake->calls)->toBe(3)->and(daftarUlangSnapshot())->toBe($before);
});

it('AC-29: records one psb and one finance activity', function () {
    $rich = daftarUlangRich($this->f);
    $applicant = $rich->applicant;
    $by = $this->f->user;
    $financeBefore = Activity::query()->where('log_name', 'keuangan')->count();

    $result = daftarUlangRun($applicant, by: $by);
    $student = $result['student'];

    $converted = daftarUlangActivities('psb', 'converted');

    expect($converted)->toHaveCount(1)
        ->and($converted->first())->subject_id->toBe($applicant->id)->subject_type->toBe($applicant->getMorphClass())->causer_id->toBe($by->id)
        ->and($converted->first()->properties->all())->toMatchArray([
            'student_id' => $student->id, 'nis' => $student->nis, 'guardian_id' => $result['guardian']->id, 'guardian_reused' => false,
            'invoices' => 3, 'fee_overrides' => 2, 'documents_copied' => 2,
        ]);

    $transfer = daftarUlangActivities('keuangan', 'applicant_billing_transferred');
    $invoiceIds = Invoice::withoutUnitScope()->where('applicant_id', $applicant->id)->orderBy('id')->pluck('id')->all();
    $overrideIds = FeeOverride::query()->where('applicant_id', $applicant->id)->orderBy('id')->pluck('id')->all();

    expect($transfer)->toHaveCount(1)
        ->and($transfer->first())->subject_id->toBe($student->id)->subject_type->toBe($student->getMorphClass())->causer_id->toBe($by->id)
        ->and($transfer->first()->properties->all())->toMatchArray(['applicant_id' => $applicant->id, 'invoice_ids' => $invoiceIds, 'fee_override_ids' => $overrideIds])
        ->and(Activity::query()->where('log_name', 'keuangan')->count())->toBe($financeBefore + 1);

    $json = json_encode([$converted->first()->properties, $transfer->first()->properties]);

    expect($json)->not->toContain('Ahmad')->not->toContain('0123456789')->not->toContain('081234567890')->not->toContain('Budi');
});

it('AC-29: still records the finance activity when nothing is transferred', function () {
    Setting::set('modules.keuangan.enabled', false);
    $applicant = pendaftaranApplicant(['status' => 'diterima']);

    $result = daftarUlangRun($applicant);

    expect($result)->toMatchArray(['invoices' => 0, 'fee_overrides' => 0])
        ->and(daftarUlangActivities('keuangan', 'applicant_billing_transferred'))->toHaveCount(1)
        ->and(daftarUlangActivities('keuangan', 'applicant_billing_transferred')->first()->properties->all())->toMatchArray(['invoice_ids' => [], 'fee_override_ids' => []]);
});

it('AC-40: reports an already converted applicant when a concurrent conversion wins', function () {
    $applicant = daftarUlangApplicant($this->f);
    $cohort = Cohort::factory()->create(['unit_id' => $this->f->sltp->id, 'entry_year' => 2025]);
    $thrown = false;
    $inserted = false;

    daftarUlangFakeStudentNumber(onFirstCall: function () use (&$thrown): void {
        $thrown = true;

        throw new UniqueConstraintViolationException('sqlite', 'insert into "students"', [], new PDOException('UNIQUE constraint failed: students.applicant_id'));
    });

    // Baris santri dari proses lain "ter-commit" tepat setelah percobaan pertama di-rollback.
    Event::listen(TransactionRolledBack::class, function () use (&$thrown, &$inserted, $applicant, $cohort): void {
        if ($thrown && ! $inserted) {
            $inserted = true;
            Student::factory()->forCohort($cohort)->create(['applicant_id' => $applicant->id]);
        }
    });

    $filesBefore = count(Storage::disk('private')->allFiles());

    daftarUlangAssertRejected(fn () => daftarUlangRun($applicant), 'already_converted', 'applicant');

    expect(Student::withoutGlobalScopes()->where('applicant_id', $applicant->id)->count())->toBe(1)
        ->and(Storage::disk('private')->allFiles())->toHaveCount($filesBefore)
        ->and(Document::withoutUnitScope()->count())->toBe(0);
});
