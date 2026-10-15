<?php

use App\Actions\Psb\ResetApplicantToken;
use App\Livewire\Psb\ApplicationStatus;
use App\Models\ApplicantDocument;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolePermissionSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->f = pendaftaranFixture();
    $this->applicant = pendaftaranApplicant(['registration_no' => 'PSB-2027-0001']);
    $this->document = pendaftaranDocument($this->applicant, $this->f->kk, ['original_name' => 'kk-ahmad.pdf']);
});

it('AC-28: lets the applicant download an own document', function () {
    $response = $this->withSession(pendaftaranSession($this->applicant))->get(route('psb.document', $this->document))->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('inline')->toContain('kk-ahmad.pdf')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('AC-28: answers 404 uniformly when the applicant has no right to the document', function (string $case) {
    $session = pendaftaranSession($this->applicant);
    $id = $this->document->id;

    switch ($case) {
        case 'unknown id':
            $id = 999999;
            break;
        case 'no session':
            $session = [];
            break;
        case 'other applicant':
            $session = pendaftaranSession(pendaftaranApplicant(['registration_no' => 'PSB-2027-0002', 'name' => 'Orang Lain'], 'WXYZ-2345-ABCD'));
            break;
        case 'stale token':
            app(ResetApplicantToken::class)->handle($this->applicant, User::factory()->create());
            break;
        case 'deleted applicant':
            $this->applicant->delete();
            break;
        case 'missing file':
            Storage::disk('private')->delete($this->document->file_path);
            break;
    }

    $this->withSession($session)->get("/psb/berkas/{$id}")->assertNotFound();
})->with(['unknown id', 'no session', 'other applicant', 'stale token', 'deleted applicant', 'missing file']);

it('AC-28: answers 404 for a non numeric document id', function () {
    $this->withSession(pendaftaranSession($this->applicant))->get('/psb/berkas/abc')->assertNotFound();
});

it('AC-28: serves the public page and documents to a scoped admin session with applicant credentials', function () {
    $this->actingAs(psbUser('admin-psb', ['SD']));
    $other = pendaftaranApplicant(['registration_no' => 'PSB-2027-0002', 'name' => 'Siti Aminah'], 'WXYZ-2345-ABCD');
    pendaftaranPayRegistration($other);

    $component = Livewire::test(ApplicationStatus::class)->set('registration_no', 'PSB-2027-0002')->set('token', 'WXYZ-2345-ABCD')->call('login');

    expect(tagihanPlainText($component))->toContain('Siti Aminah');

    $component->set("documents.{$this->f->kk->id}", pendaftaranPdf('kk.pdf', 100))->call('uploadDocument', $this->f->kk->id);

    $document = ApplicantDocument::withoutUnitScope()->where('applicant_id', $other->id)->sole();

    $this->withSession(pendaftaranSession($other))->get(route('psb.document', $document))->assertOk();
});

it('AC-29: lets permitted staff download any document', function (string $role) {
    $this->actingAs(psbUser($role))->get("/admin/applicant-documents/{$this->document->id}")->assertOk();
})->with(['admin-psb', 'super-admin']);

it('AC-29: serves the staff download inline with the stored content type', function () {
    $response = $this->actingAs(psbUser('admin-psb'))->get("/admin/applicant-documents/{$this->document->id}")->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('inline')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('AC-29: forbids the staff download to roles without applicant permission', function (string $role) {
    $this->actingAs(psbUser($role))->get("/admin/applicant-documents/{$this->document->id}")->assertForbidden();
})->with(['bendahara', 'admin-sekolah', 'kesantrian']);

it('AC-29: hides documents of other units from a scoped admin behind a 404', function () {
    $this->actingAs(psbUser('admin-psb', ['SD']))->get("/admin/applicant-documents/{$this->document->id}")->assertNotFound();
});

it('AC-29: lets a scoped admin download documents of the own unit', function () {
    $this->actingAs(psbUser('admin-psb', ['SLTP']))->get("/admin/applicant-documents/{$this->document->id}")->assertOk();
});

it('AC-29: answers 404 when the file is missing', function () {
    Storage::disk('private')->delete($this->document->file_path);

    $this->actingAs(psbUser('admin-psb'))->get("/admin/applicant-documents/{$this->document->id}")->assertNotFound();
});

it('AC-29: sends guests to the panel login', function () {
    $this->get("/admin/applicant-documents/{$this->document->id}")->assertRedirect('/admin/login');
});

it('AC-29: returns 403 when the psb module is off', function () {
    Setting::set('modules.psb.enabled', false);

    $this->actingAs(psbUser('super-admin'))->get("/admin/applicant-documents/{$this->document->id}")->assertForbidden();
});

it('AC-29: keeps documents off the public disk and out of the default storage route', function () {
    expect(Storage::disk('public')->allFiles())->toBe([])
        ->and(Storage::disk('private')->exists($this->document->file_path))->toBeTrue();

    expect($this->get('/storage/'.$this->document->file_path)->status())->toBeIn([403, 404]);
});
