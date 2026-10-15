<?php

use App\Actions\Psb\GenerateRegistrationInvoices;
use App\Actions\Psb\UploadApplicantDocument;
use App\Models\AdmissionRequirement;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->f = pendaftaranFixture();
});

function pendaftaranUpload(Applicant $applicant, AdmissionRequirement $requirement, UploadedFile $file): ApplicantDocument
{
    return app(UploadApplicantDocument::class)->handle($applicant, $requirement, $file);
}

/**
 * Jumlah berkas di disk private dan jumlah baris `applicant_documents`.
 *
 * @return array{files: int, rows: int}
 */
function pendaftaranUploadState(): array
{
    return ['files' => count(Storage::disk('private')->allFiles()), 'rows' => ApplicantDocument::query()->count()];
}

it('AC-17: knows when the registration fee is settled', function (string $case, bool $settled) {
    $applicant = pendaftaranApplicant();
    $invoice = fn (array $attributes = []) => Invoice::factory()->create([
        'student_id' => null, 'applicant_id' => $applicant->id, 'academic_year_id' => $this->f->y2027->id, 'billing_group' => 'PSB_DAFTAR', 'total' => 250000, ...$attributes,
    ]);
    $withItem = function (Invoice $invoice, FeeType $type): Invoice {
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'fee_type_id' => $type->id, 'base_amount' => 250000, 'discount_amount' => 0, 'amount' => 250000]);

        return $invoice;
    };

    match ($case) {
        'no invoice' => null,
        'unpaid' => $withItem($invoice(), $this->f->formulir),
        'partial' => $withItem($invoice(['status' => 'sebagian', 'paid_total' => 100000]), $this->f->formulir),
        'paid' => $withItem($invoice(['status' => 'lunas', 'paid_total' => 250000]), $this->f->formulir),
        'only cancelled' => $withItem($invoice(['status' => 'batal']), $this->f->formulir),
        'unpaid acceptance fee' => $withItem($invoice(['billing_group' => 'PSB_DITERIMA']), psbFeeType('saat_diterima', ['code' => 'PANGKAL', 'billing_group' => 'PSB_DITERIMA'])),
    };

    expect($applicant->fresh()->registrationFeeSettled())->toBe($settled);
})->with([
    'no invoice' => ['no invoice', true],
    'unpaid' => ['unpaid', false],
    'partial' => ['partial', false],
    'paid' => ['paid', true],
    'only cancelled' => ['only cancelled', true],
    'unpaid acceptance fee' => ['unpaid acceptance fee', true],
]);

it('AC-17: ignores the invoices of another applicant', function () {
    $other = pendaftaranApplicant(['name' => 'Orang Lain']);
    app(GenerateRegistrationInvoices::class)->handle($other);
    $applicant = pendaftaranApplicant();

    expect($applicant->registrationFeeSettled())->toBeTrue()->and($other->fresh()->registrationFeeSettled())->toBeFalse();
});

it('AC-17: lets an applicant upload only while the status is submitted and the fee is settled', function (string $status, bool $paid, bool $accepts) {
    $applicant = pendaftaranApplicant();
    app(GenerateRegistrationInvoices::class)->handle($applicant);

    if ($paid) {
        pendaftaranPayRegistration($applicant);
    }

    $applicant->forceFill(['status' => $status])->save();

    expect($applicant->fresh()->acceptsUploads())->toBe($accepts);
})->with([
    'submitted and paid' => ['diajukan', true, true],
    'submitted and unpaid' => ['diajukan', false, false],
    'verified and paid' => ['diverifikasi', true, false],
    'rejected and paid' => ['ditolak', true, false],
]);

it('AC-18: stores a document privately', function () {
    $applicant = pendaftaranApplicant();
    pendaftaranPayRegistration($applicant);

    $document = pendaftaranUpload($applicant, $this->f->kk, pendaftaranPdf('kk.pdf', 100));

    expect(ApplicantDocument::query()->sole()->is($document))->toBeTrue()
        ->and($document)
        ->applicant_id->toBe($applicant->id)
        ->admission_requirement_id->toBe($this->f->kk->id)
        ->status->toBe('menunggu')
        ->original_name->toBe('kk.pdf')
        ->mime_type->toBe('application/pdf')
        ->size_kb->toBe(100)
        ->and($document->file_path)->toStartWith("psb/applicants/{$applicant->id}/")->toEndWith('.pdf')->not->toContain('kk')
        ->and(Storage::disk('private')->exists($document->file_path))->toBeTrue()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('AC-18: accepts jpg and png documents with an extension from the content type', function (string $name, string $mime, string $extension) {
    $applicant = pendaftaranApplicant();
    pendaftaranPayRegistration($applicant);
    $document = pendaftaranUpload($applicant, $this->f->akta, UploadedFile::fake()->image($name));

    expect($document->mime_type)->toBe($mime)->and($document->file_path)->toEndWith(".{$extension}")
        ->and(Storage::disk('private')->exists($document->file_path))->toBeTrue();
})->with([['akta.jpg', 'image/jpeg', 'jpg'], ['akta.png', 'image/png', 'png']]);

it('AC-18: does not trust the client file name for the stored name or extension', function () {
    $applicant = pendaftaranApplicant();
    pendaftaranPayRegistration($applicant);

    $document = pendaftaranUpload($applicant, $this->f->kk, UploadedFile::fake()->create('../../kk.php', 10, 'application/pdf'));

    expect($document->file_path)->toStartWith("psb/applicants/{$applicant->id}/")->toEndWith('.pdf')->not->toContain('..')->not->toContain('php')
        ->and($document->original_name)->toBe('kk.php');
});

it('AC-18: truncates a very long original name to 255 characters', function () {
    $applicant = pendaftaranApplicant();
    pendaftaranPayRegistration($applicant);

    $document = pendaftaranUpload($applicant, $this->f->kk, UploadedFile::fake()->create(str_repeat('a', 296).'.pdf', 10, 'application/pdf'));

    expect(mb_strlen($document->original_name))->toBe(255);
});

it('AC-18: accepts a document of exactly 2048 KB', function () {
    $applicant = pendaftaranApplicant();
    pendaftaranPayRegistration($applicant);

    expect(pendaftaranUpload($applicant, $this->f->kk, pendaftaranPdf('kk.pdf', 2048))->size_kb)->toBe(2048);
});

it('AC-18: allows an optional requirement for the applicant unit', function () {
    $applicant = pendaftaranApplicant();
    pendaftaranPayRegistration($applicant);

    expect(pendaftaranUpload($applicant, $this->f->rapor, pendaftaranPdf('rapor.pdf'))->admission_requirement_id)->toBe($this->f->rapor->id);
});

it('AC-19: replaces a rejected document and removes the old file', function () {
    $applicant = pendaftaranApplicant();
    pendaftaranPayRegistration($applicant);
    $reviewer = User::factory()->create();
    $old = pendaftaranDocument($applicant, $this->f->kk, ['status' => 'ditolak', 'note' => 'Buram', 'verified_by' => $reviewer->id, 'verified_at' => now()]);
    $oldPath = $old->file_path;

    $new = pendaftaranUpload($applicant, $this->f->kk, pendaftaranPdf('kk-baru.pdf', 120));

    expect($new->id)->toBe($old->id)
        ->and(ApplicantDocument::query()->count())->toBe(1)
        ->and($new->fresh())
        ->status->toBe('menunggu')
        ->note->toBeNull()
        ->verified_by->toBeNull()
        ->verified_at->toBeNull()
        ->original_name->toBe('kk-baru.pdf')
        ->size_kb->toBe(120)
        ->and($new->file_path)->not->toBe($oldPath)
        ->and(Storage::disk('private')->exists($oldPath))->toBeFalse()
        ->and(Storage::disk('private')->exists($new->file_path))->toBeTrue()
        ->and(Storage::disk('private')->allFiles())->toHaveCount(1);
});

it('AC-19: replaces a pending document too', function () {
    $applicant = pendaftaranApplicant();
    pendaftaranPayRegistration($applicant);
    $old = pendaftaranDocument($applicant, $this->f->kk, ['status' => 'menunggu']);

    $new = pendaftaranUpload($applicant, $this->f->kk, pendaftaranPdf('kk-2.pdf'));

    expect($new->id)->toBe($old->id)->and(Storage::disk('private')->allFiles())->toHaveCount(1);
});

it('AC-20: rejects uploads without changing rows or files', function (Closure $case, string $reason, ?string $field) {
    $applicant = pendaftaranApplicant();
    pendaftaranPayRegistration($applicant);
    [$requirement, $file] = $case($applicant, $this->f);
    $before = pendaftaranUploadState();

    pendaftaranAssertRejected(fn () => pendaftaranUpload($applicant->fresh(), $requirement, $file), $reason, $field);

    expect(pendaftaranUploadState())->toBe($before);
})->with([
    'not for the unit' => [fn ($a, $f) => [$f->ijazah, pendaftaranPdf()], 'requirement_not_applicable', null],
    'already valid' => [function ($a, $f) {
        pendaftaranDocument($a, $f->kk, ['status' => 'valid']);

        return [$f->kk, pendaftaranPdf()];
    }, 'document_already_valid', null],
    'text content named pdf' => [fn ($a, $f) => [$f->kk, pendaftaranRealFile('kk.pdf', 'ini hanya teks biasa')], 'file_type_invalid', 'file'],
    'too large' => [fn ($a, $f) => [$f->kk, pendaftaranPdf('kk.pdf', 2049)], 'file_too_large', 'file'],
]);

it('AC-20: rejects uploads while the registration fee is unpaid', function () {
    $applicant = pendaftaranApplicant();
    app(GenerateRegistrationInvoices::class)->handle($applicant);

    pendaftaranAssertRejected(fn () => pendaftaranUpload($applicant->fresh(), $this->f->kk, pendaftaranPdf()), 'registration_fee_unpaid');

    expect(pendaftaranUploadState())->toBe(['files' => 0, 'rows' => 0]);
});

it('AC-20: rejects uploads once the applicant is past submission', function (string $status) {
    $applicant = pendaftaranApplicant();
    pendaftaranPayRegistration($applicant);
    $applicant->forceFill(['status' => $status])->save();

    pendaftaranAssertRejected(fn () => pendaftaranUpload($applicant->fresh(), $this->f->kk, pendaftaranPdf()), 'uploads_closed');

    expect(pendaftaranUploadState())->toBe(['files' => 0, 'rows' => 0]);
})->with(['diverifikasi', 'diterima', 'cadangan', 'ditolak']);

it('AC-20: reports a closed upload before an unpaid fee', function () {
    $applicant = pendaftaranApplicant(['status' => 'ditolak']);
    app(GenerateRegistrationInvoices::class)->handle($applicant->fresh()->forceFill(['status' => 'diajukan']));

    pendaftaranAssertRejected(fn () => pendaftaranUpload($applicant->fresh(), $this->f->kk, pendaftaranPdf()), 'uploads_closed');
});

it('AC-20: keeps the old file when a replacement is rejected', function () {
    $applicant = pendaftaranApplicant();
    pendaftaranPayRegistration($applicant);
    $old = pendaftaranDocument($applicant, $this->f->kk, ['status' => 'ditolak', 'note' => 'Buram']);

    pendaftaranAssertRejected(fn () => pendaftaranUpload($applicant, $this->f->kk, pendaftaranPdf('besar.pdf', 3000)), 'file_too_large', 'file');

    expect(Storage::disk('private')->exists($old->file_path))->toBeTrue()
        ->and($old->fresh())->status->toBe('ditolak')->note->toBe('Buram');
});
