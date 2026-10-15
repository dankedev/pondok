<?php

use App\Models\AdmissionRequirement;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\FeeOverride;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\Student;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function pendaftaranApplicantAttributes(array $overrides = []): array
{
    $applicant = Applicant::factory()->make($overrides);

    return array_merge($applicant->getAttributes(), ['access_token_hash' => 'x', 'created_at' => now(), 'updated_at' => now()], $overrides);
}

it('AC-1: creates the applicant tables', function (string $table, array $columns) {
    expect(Schema::hasColumns($table, $columns))->toBeTrue();
})->with([
    'applicants' => ['applicants', [
        'id', 'admission_wave_id', 'admission_track_id', 'unit_id', 'registration_no', 'access_token_hash', 'name', 'nisn', 'gender',
        'birth_place', 'birth_date', 'previous_school', 'guardian_name', 'guardian_phone', 'email', 'status', 'reviewed_by',
        'reviewed_at', 'review_note', 'consented_at', 'created_at', 'updated_at', 'deleted_at',
    ]],
    'applicant documents' => ['applicant_documents', [
        'id', 'applicant_id', 'admission_requirement_id', 'file_path', 'original_name', 'mime_type', 'size_kb', 'status', 'note',
        'verified_by', 'verified_at', 'created_at', 'updated_at',
    ]],
]);

it('AC-1: keeps consented_at nullable and drops deleted_at from applicant documents', function () {
    $consented = collect(Schema::getColumns('applicants'))->firstWhere('name', 'consented_at');

    expect($consented['nullable'])->toBeTrue()
        ->and(Schema::hasColumn('applicant_documents', 'deleted_at'))->toBeFalse()
        ->and(class_uses_recursive(Applicant::class))->toContain(SoftDeletes::class)
        ->and(class_uses_recursive(ApplicantDocument::class))->not->toContain(SoftDeletes::class);
});

it('AC-2: rejects a duplicate registration number', function () {
    Applicant::factory()->create(['registration_no' => 'PSB-2027-0001']);

    expect(fn () => Applicant::factory()->create(['registration_no' => 'PSB-2027-0001']))->toThrow(UniqueConstraintViolationException::class)
        ->and(Applicant::query()->count())->toBe(1);
});

it('AC-2: rejects two documents for the same applicant and requirement', function () {
    $applicant = Applicant::factory()->create();
    $requirement = AdmissionRequirement::factory()->create();
    ApplicantDocument::factory()->create(['applicant_id' => $applicant->id, 'admission_requirement_id' => $requirement->id]);

    expect(fn () => ApplicantDocument::factory()->create(['applicant_id' => $applicant->id, 'admission_requirement_id' => $requirement->id]))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(ApplicantDocument::query()->count())->toBe(1);

    ApplicantDocument::factory()->create(['applicant_id' => $applicant->id, 'admission_requirement_id' => AdmissionRequirement::factory()->create()->id]);

    expect(ApplicantDocument::query()->count())->toBe(2);
});

it('AC-2: indexes applicants by wave and status and by created_at', function () {
    $columns = collect(Schema::getIndexes('applicants'))->pluck('columns')->all();

    expect($columns)->toContain(['admission_wave_id', 'status'])->toContain(['created_at']);
});

it('AC-2: restricts deleting rows that applicants or documents point to', function (Closure $parent) {
    [$model] = $parent();

    expect(fn () => $model->delete())->toThrow(QueryException::class)
        ->and($model::query()->whereKey($model->getKey())->exists())->toBeTrue();
})->with([
    'wave' => [fn () => [Applicant::factory()->create()->admissionWave]],
    'track' => [fn () => [Applicant::factory()->create()->admissionTrack]],
    'unit' => [fn () => [Applicant::factory()->create()->unit]],
    'requirement' => [function () {
        $document = ApplicantDocument::factory()->create();

        return [$document->requirement];
    }],
]);

it('AC-2: restricts force deleting an applicant with documents or invoices', function () {
    $withDocument = Applicant::factory()->create();
    ApplicantDocument::factory()->create(['applicant_id' => $withDocument->id]);
    $withInvoice = Applicant::factory()->create();
    Invoice::factory()->create(['student_id' => null, 'applicant_id' => $withInvoice->id, 'billing_group' => 'PSB']);

    expect(fn () => $withDocument->forceDelete())->toThrow(QueryException::class)
        ->and(fn () => $withInvoice->forceDelete())->toThrow(QueryException::class)
        ->and(Applicant::withTrashed()->count())->toBe(2);
});

it('AC-2: declares restrictive foreign keys on both tables', function (string $table, string $column, string $parent) {
    $key = collect(Schema::getForeignKeys($table))->first(fn (array $key): bool => $key['columns'] === [$column]);

    expect($key)->not->toBeNull()
        ->and($key['foreign_table'])->toBe($parent)
        ->and(strtolower($key['on_delete']))->toBe('restrict');
})->with([
    ['applicants', 'admission_wave_id', 'admission_waves'],
    ['applicants', 'admission_track_id', 'admission_tracks'],
    ['applicants', 'unit_id', 'units'],
    ['applicants', 'reviewed_by', 'users'],
    ['applicant_documents', 'applicant_id', 'applicants'],
    ['applicant_documents', 'admission_requirement_id', 'admission_requirements'],
    ['applicant_documents', 'verified_by', 'users'],
]);

it('AC-2: stores every applicant status and rejects others at the database level', function (string $status) {
    $applicant = Applicant::factory()->create(['status' => $status]);

    expect($applicant->fresh()->status)->toBe($status);
})->with(['draft', 'diajukan', 'diverifikasi', 'diterima', 'cadangan', 'ditolak']);

it('AC-2: rejects an applicant status outside the enum', function () {
    $attributes = pendaftaranApplicantAttributes(['registration_no' => 'PSB-2027-0099']);
    unset($attributes['deleted_at']);

    DB::table('applicants')->insert([...$attributes, 'status' => 'batal']);
})->throws(QueryException::class);

it('AC-2: stores every document status and rejects others at the database level', function (string $status) {
    $document = ApplicantDocument::factory()->create(['status' => $status]);

    expect($document->fresh()->status)->toBe($status);
})->with(['menunggu', 'valid', 'ditolak']);

it('AC-2: rejects a document status outside the enum', function () {
    $document = ApplicantDocument::factory()->create();

    DB::table('applicant_documents')->where('id', $document->id)->update(['status' => 'diganti']);
})->throws(QueryException::class);

it('AC-3: adds the applicant foreign keys and keeps indexes, uniques and generated columns', function () {
    pendaftaranAssertForeignKeyStructure();
});

it('AC-3: rejects an unknown applicant id on students, overrides and invoices', function (string $table) {
    $row = match ($table) {
        'students' => fn () => Student::factory()->create(['applicant_id' => 999999]),
        'fee_overrides' => fn () => FeeOverride::factory()->create(['student_id' => null, 'applicant_id' => 999999]),
        'invoices' => fn () => Invoice::factory()->create(['student_id' => null, 'applicant_id' => 999999]),
    };

    expect($row)->toThrow(QueryException::class);
})->with(['students', 'fee_overrides', 'invoices']);

it('AC-3: restricts deleting an applicant that students, overrides or invoices point to', function (string $table) {
    $applicant = Applicant::factory()->create();

    match ($table) {
        'students' => Student::factory()->create(['applicant_id' => $applicant->id]),
        'fee_overrides' => FeeOverride::factory()->create(['student_id' => null, 'applicant_id' => $applicant->id]),
        'invoices' => Invoice::factory()->create(['student_id' => null, 'applicant_id' => $applicant->id]),
    };

    expect(fn () => $applicant->forceDelete())->toThrow(QueryException::class)
        ->and(Applicant::withTrashed()->whereKey($applicant->id)->exists())->toBeTrue();
})->with(['students', 'fee_overrides', 'invoices']);

it('AC-3: keeps the active invoice marker rules for applicant invoices', function () {
    $applicantId = Applicant::factory()->create()->id;
    $cancelled = Invoice::factory()->create(['student_id' => null, 'applicant_id' => $applicantId, 'billing_group' => 'PSB_DAFTAR', 'status' => 'batal']);
    $active = Invoice::factory()->create(['student_id' => null, 'applicant_id' => $applicantId, 'billing_group' => 'PSB_DAFTAR']);

    expect(fn () => Invoice::factory()->create(['student_id' => null, 'applicant_id' => $applicantId, 'billing_group' => 'PSB_DAFTAR']))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(DB::table('invoices')->where('id', $active->id)->value('active_marker'))->toBe(1)
        ->and(DB::table('invoices')->where('id', $cancelled->id)->value('active_marker'))->toBeNull();
});

/**
 * Data lintas tabel yang dirujuk migration FK: santri (enrollment, wali, kamar, keringanan, tagihan, item, pembayaran,
 * alokasi), pendaftar (tagihan, keringanan, berkas), dan santri dengan applicant_id.
 *
 * @return array<string, int>
 */
function pendaftaranSqliteRows(): array
{
    $tables = ['students', 'enrollments', 'student_guardian', 'room_assignments', 'fee_overrides', 'invoices', 'invoice_items', 'payments', 'payment_allocations', 'applicants', 'applicant_documents'];

    return collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
}

it('AC-4: rebuilds the tables on sqlite with data and keeps every row', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Hanya relevan untuk SQLite.');
    }

    $this->seed(RolePermissionSeeder::class);
    $f = tagihanFixture();
    $student = tagihanStudent($f, resident: true);
    Enrollment::factory()->create(['student_id' => $student->id, 'classroom_id' => Classroom::factory()->create(['academic_year_id' => $f->year->id])->id]);
    $student->guardians()->attach(Guardian::factory()->create()->id, ['relation' => 'ayah', 'is_billing_contact' => true]);
    keringananOverride(['student_id' => $student->id, 'fee_type_id' => $f->spp->id]);
    $invoice = bayarInvoice($f, $student);
    bayarRecord($invoice, 500000);
    $cancelled = tagihanInvoice($student, $f->year, ['billing_group' => 'LAIN', 'status' => 'batal']);

    $applicant = Applicant::factory()->create(['unit_id' => $f->sltp->id]);
    $applicantInvoice = Invoice::factory()->create(['student_id' => null, 'applicant_id' => $applicant->id, 'academic_year_id' => $f->year->id, 'billing_group' => 'PSB_DAFTAR']);
    FeeOverride::factory()->create(['student_id' => null, 'applicant_id' => $applicant->id, 'fee_type_id' => $f->spp->id]);
    ApplicantDocument::factory()->create(['applicant_id' => $applicant->id]);
    $converted = Student::factory()->forCohort($f->cohort)->create(['applicant_id' => $applicant->id]);

    $rowsBefore = pendaftaranSqliteRows();
    $markersBefore = DB::table('invoices')->orderBy('id')->pluck('active_marker', 'id')->all();
    $linkBefore = DB::table('students')->where('id', $converted->id)->value('applicant_id');

    $migrator = app('migrator');
    $resolve = fn (string $path) => (fn () => $this->resolvePath($path))->call($migrator);
    $paths = collect(['students', 'fee_overrides', 'invoices'])->map(function (string $table): string {
        $found = glob(database_path("migrations/*_add_applicant_foreign_key_to_{$table}_table.php"));
        expect($found)->toHaveCount(1);

        return $found[0];
    });

    foreach ($paths->reverse() as $path) {
        $resolve($path)->down();
    }

    expect(collect(Schema::getForeignKeys('invoices'))->contains(fn (array $key): bool => $key['columns'] === ['applicant_id']))->toBeFalse();

    foreach ($paths as $path) {
        $resolve($path)->up();
    }

    expect(pendaftaranSqliteRows())->toBe($rowsBefore)
        ->and(DB::table('invoices')->orderBy('id')->pluck('active_marker', 'id')->all())->toBe($markersBefore)
        ->and($markersBefore[$cancelled->id])->toBeNull()
        ->and($markersBefore[$applicantInvoice->id])->toBe(1)
        ->and(DB::table('students')->where('id', $converted->id)->value('applicant_id'))->toBe($linkBefore)
        ->and(DB::select('PRAGMA foreign_key_check'))->toBe([]);

    pendaftaranAssertForeignKeyStructure();
});
