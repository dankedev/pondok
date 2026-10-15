<?php

use App\Actions\Psb\CheckApplicantCredentials;
use App\Actions\Psb\GenerateRegistrationInvoices;
use App\Actions\Psb\ResetApplicantToken;
use App\Livewire\Psb\ApplicationStatus;
use App\Models\ApplicantDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->f = pendaftaranFixture();
    $this->applicant = pendaftaranApplicant(['registration_no' => 'PSB-2027-0001', 'name' => 'Ahmad Fauzan']);
    app(GenerateRegistrationInvoices::class)->handle($this->applicant);
});

/**
 * Kontrak komponen `ApplicationStatus`: properti `registration_no` dan `token`, aksi `login`, `logout`,
 * properti unggah `documents` (kunci id syarat) dan aksi `uploadDocument(int $requirementId)`.
 */
function pendaftaranLogin(string $number = 'PSB-2027-0001', string $token = 'ABCD-EFGH-JK23'): Testable
{
    return Livewire::test(ApplicationStatus::class)->set('registration_no', $number)->set('token', $token)->call('login');
}

it('AC-24: shows the status, invoices and requirements with valid credentials', function () {
    $component = pendaftaranLogin();
    $text = tagihanPlainText($component);

    expect($text)->toContain('Ahmad Fauzan')
        ->toContain('PSB-2027-0001')
        ->toContain(__('pondok.applicant.statuses.diajukan'))
        ->toContain('Rp 250.000')
        ->toContain(__('pondok.invoice.statuses.belum'))
        ->toContain('Kartu Keluarga')
        ->toContain('Akta kelahiran')
        ->toContain('Rapor')
        ->toContain(__('pondok.psb_public.required'))
        ->toContain(__('pondok.psb_public.optional'))
        ->not->toContain('Ijazah');
});

it('AC-24: accepts a lowercase token without hyphens and a lowercase number with spaces', function () {
    $text = tagihanPlainText(pendaftaranLogin('  psb-2027-0001 ', 'abcdefghjk23'));

    expect($text)->toContain('Ahmad Fauzan');
});

it('AC-24: gives one message for a wrong token, an unknown number and a deleted applicant', function (string $case) {
    $number = 'PSB-2027-0001';
    $token = 'ABCD-EFGH-JK23';

    match ($case) {
        'wrong token' => $token = 'ABCD-EFGH-JK24',
        'unknown number' => $number = 'PSB-2027-0999',
        'deleted' => $this->applicant->delete(),
    };

    $component = pendaftaranLogin($number, $token);

    expect(pendaftaranSeeMessage($component, 'credentials_invalid'))->toBeTrue()
        ->and(tagihanPlainText($component))->not->toContain('Ahmad Fauzan');
})->with(['wrong token', 'unknown number', 'deleted']);

it('AC-25: blocks the fifth plus one failed attempt from the same ip and recovers after a minute', function () {
    foreach (range(1, 5) as $ignored) {
        pendaftaranLogin('PSB-2027-0001', 'SALAH-SALAH-SALA');
    }

    $blocked = pendaftaranLogin();

    expect(pendaftaranSeeMessage($blocked, 'too_many_attempts'))->toBeTrue()
        ->and(tagihanPlainText($blocked))->not->toContain('Ahmad Fauzan');

    $this->travel(61)->seconds();

    expect(tagihanPlainText(pendaftaranLogin()))->toContain('Ahmad Fauzan');
});

it('AC-25: limits failed attempts per number even from other addresses', function () {
    foreach (range(1, 10) as $ignored) {
        pendaftaranLogin('PSB-2027-0001', 'SALAH-SALAH-SALA');
        RateLimiter::clear('psb-status:127.0.0.1');
    }

    $blocked = pendaftaranLogin();

    expect(pendaftaranSeeMessage($blocked, 'too_many_attempts'))->toBeTrue()
        ->and(tagihanPlainText($blocked))->not->toContain('Ahmad Fauzan');
});

it('AC-25: does not let failures on one number lock another number', function () {
    $other = pendaftaranApplicant(['registration_no' => 'PSB-2027-0002', 'name' => 'Siti Aminah'], 'WXYZ-2345-ABCD');

    foreach (range(1, 10) as $ignored) {
        pendaftaranLogin('PSB-2027-0001', 'SALAH-SALAH-SALA');
        RateLimiter::clear('psb-status:127.0.0.1');
    }

    expect(tagihanPlainText(pendaftaranLogin('PSB-2027-0002', 'WXYZ-2345-ABCD')))->toContain('Siti Aminah');
});

it('AC-26: keeps the session for a new component and logs out on request', function () {
    pendaftaranLogin();

    $again = Livewire::test(ApplicationStatus::class);

    expect(tagihanPlainText($again))->toContain('Ahmad Fauzan')->not->toContain(__('pondok.psb_public.login_submit'));

    $again->call('logout');

    expect(tagihanPlainText($again))->toContain(__('pondok.psb_public.login_submit'))->not->toContain('Ahmad Fauzan')
        ->and(tagihanPlainText(Livewire::test(ApplicationStatus::class)))->not->toContain('Ahmad Fauzan');
});

it('AC-26: drops the session after the token is reset', function () {
    pendaftaranLogin();
    $newToken = app(ResetApplicantToken::class)->handle($this->applicant, User::factory()->create());

    expect(tagihanPlainText(Livewire::test(ApplicationStatus::class)))->not->toContain('Ahmad Fauzan')
        ->and(app(CheckApplicantCredentials::class)->handle('PSB-2027-0001', 'ABCD-EFGH-JK23'))->toBeNull()
        ->and(app(CheckApplicantCredentials::class)->handle('PSB-2027-0001', $newToken)?->id)->toBe($this->applicant->id);

    expect(tagihanPlainText(pendaftaranLogin('PSB-2027-0001', $newToken)))->toContain('Ahmad Fauzan');
});

it('AC-26: drops the session when the applicant was deleted', function () {
    pendaftaranLogin();
    $this->applicant->delete();

    expect(tagihanPlainText(Livewire::test(ApplicationStatus::class)))->not->toContain('Ahmad Fauzan');
});

it('AC-27: locks uploads while the registration fee is unpaid', function () {
    $component = pendaftaranLogin();

    expect(tagihanPlainText($component))->toContain(__('pondok.psb_public.uploads_locked_unpaid'));

    $component->assertDontSeeHtml('type="file"');
});

it('AC-27: uploads a document once the fee is paid', function () {
    pendaftaranPayRegistration($this->applicant);
    $component = pendaftaranLogin()->assertSeeHtml('type="file"');

    expect(tagihanPlainText($component))->not->toContain(__('pondok.psb_public.uploads_locked_unpaid'));

    $component->set("documents.{$this->f->kk->id}", pendaftaranPdf('kk.pdf', 100))->call('uploadDocument', $this->f->kk->id);

    $document = ApplicantDocument::query()->sole();

    expect($document)->applicant_id->toBe($this->applicant->id)->admission_requirement_id->toBe($this->f->kk->id)->status->toBe('menunggu')
        ->and(Storage::disk('private')->exists($document->file_path))->toBeTrue()
        ->and(tagihanPlainText($component))->toContain(__('pondok.applicant_document.statuses.menunggu'));
});

it('AC-27: shows the rejection note and keeps the upload input for a rejected document', function () {
    pendaftaranPayRegistration($this->applicant);
    pendaftaranDocument($this->applicant, $this->f->kk, ['status' => 'ditolak', 'note' => 'Foto buram, unggah ulang']);

    $component = pendaftaranLogin();

    expect(tagihanPlainText($component))->toContain('Foto buram, unggah ulang')->toContain(__('pondok.applicant_document.statuses.ditolak'))
        ->and(substr_count($component->html(), 'type="file"'))->toBe(3);
});

it('AC-27: offers no upload input for a valid document', function () {
    pendaftaranPayRegistration($this->applicant);
    pendaftaranDocument($this->applicant, $this->f->kk, ['status' => 'valid']);

    $component = pendaftaranLogin();

    expect(substr_count($component->html(), 'type="file"'))->toBe(2)
        ->and(tagihanPlainText($component))->toContain(__('pondok.applicant_document.statuses.valid'));
});

it('AC-27: closes uploads once the applicant is past submission', function () {
    pendaftaranPayRegistration($this->applicant);
    $this->applicant->forceFill(['status' => 'diverifikasi'])->save();

    $component = pendaftaranLogin();

    expect(tagihanPlainText($component))->toContain(__('pondok.psb_public.uploads_closed'))
        ->and(substr_count($component->html(), 'type="file"'))->toBe(0);
});

it('AC-27: refuses an upload that bypasses the locked input while the fee is unpaid', function () {
    $component = pendaftaranLogin();

    try {
        $component->set("documents.{$this->f->kk->id}", pendaftaranPdf())->call('uploadDocument', $this->f->kk->id);
    } catch (Throwable) {
    }

    expect(ApplicantDocument::query()->count())->toBe(0)->and(Storage::disk('private')->allFiles())->toBe([]);
});

it('AC-28a: restricts livewire temporary uploads to small pdf and images', function () {
    $rules = config('livewire.temporary_file_upload.rules');
    $rules = is_string($rules) ? explode('|', $rules) : Arr::wrap($rules);

    expect($rules)->toContain('max:2048')->toContain('mimes:pdf,jpg,jpeg,png');
});

it('AC-28a: rejects oversized and executable temporary uploads', function (Closure $makeFile) {
    pendaftaranPayRegistration($this->applicant);
    $component = pendaftaranLogin();

    $component->set("documents.{$this->f->kk->id}", $makeFile());

    $component->assertHasErrors("documents.{$this->f->kk->id}");

    expect(ApplicantDocument::query()->count())->toBe(0);
})->with([
    '3 MB pdf' => [fn () => UploadedFile::fake()->create('besar.pdf', 3072, 'application/pdf')],
    'exe file' => [fn () => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')],
]);
