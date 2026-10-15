<?php

use App\Filament\Resources\Documents\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Guardians\Pages\EditGuardian;
use App\Filament\Resources\Staff\Pages\EditStaff;
use App\Filament\Resources\Students\Pages\EditStudent;
use App\Models\Document;
use App\Models\Guardian;
use App\Models\Setting;
use App\Models\Staff;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = dokumenFixture();
    $this->actingAs($this->f->admin);
});

/**
 * Relation manager dokumen untuk pemilik (halaman edit sesuai jenis pemilik).
 */
function dokumenManager(Model $owner): Testable
{
    $page = match (true) {
        $owner instanceof Guardian => EditGuardian::class,
        $owner instanceof Staff => EditStaff::class,
        default => EditStudent::class,
    };

    return Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => $page]);
}

/**
 * ID opsi jenis dokumen pada modal unggah.
 *
 * @return list<int|string>
 */
function dokumenTypeOptions(Testable $component): array
{
    $ids = [];
    $component->assertSchemaComponentExists('document_type_id', checkComponentUsing: function ($select) use (&$ids): bool {
        $ids = array_keys($select->getOptions());

        return true;
    });

    return $ids;
}

it('AC-36: uploads and replaces student documents', function () {
    $f = $this->f;

    dokumenManager($f->s1)
        ->assertSuccessful()
        ->callAction(TestAction::make('upload')->table(), [
            'document_type_id' => $f->types['KK']->id,
            'file' => dokumenPdf('kk.pdf'),
            'number' => '3201234567890001',
            'issued_on' => '2020-01-10',
        ])
        ->assertHasNoFormErrors()
        ->assertNotified();

    $document = Document::query()->sole();

    expect($document->documentable->is($f->s1))->toBeTrue()
        ->and($document->documentable_type)->toBe('student')
        ->and($document->status)->toBe('menunggu')
        ->and($document->uploaded_by)->toBe($f->admin->id)
        ->and($document->number)->toBe('3201234567890001')
        ->and(Storage::disk('private')->exists($document->file_path))->toBeTrue()
        ->and(Storage::disk('public')->allFiles())->toBe([]);

    dokumenManager($f->s1)
        ->assertCanSeeTableRecords([$document])
        ->assertActionVisible(TestAction::make('replace')->table($document))
        ->callAction(TestAction::make('replace')->table($document), ['file' => dokumenPdf('kk-baru.pdf'), 'number' => '3201234567899999'])
        ->assertHasNoFormErrors()
        ->assertNotified();

    $new = Document::query()->whereNull('replaced_by_id')->sole();

    expect(Document::query()->count())->toBe(2)
        ->and($document->fresh()->replaced_by_id)->toBe($new->id)
        ->and($new->status)->toBe('menunggu')
        ->and($new->document_type_id)->toBe($document->document_type_id);

    dokumenManager($f->s1)->assertCanSeeTableRecords([$new])->assertCanNotSeeTableRecords([$document]);
    $all = dokumenManager($f->s1)->removeTableFilter('current')->assertCanSeeTableRecords([$new, $document]);

    expect(tagihanPlainText($all))->toContain('Diganti');
});

it('AC-36: offers only active student types in the upload form', function () {
    $f = $this->f;
    $inactive = dokumenType(['code' => 'LAMA', 'owner' => 'santri', 'is_active' => false]);
    $guardianType = dokumenType(['code' => 'WALIDOK', 'owner' => 'wali']);

    $ids = dokumenTypeOptions(dokumenManager($f->s1)->mountAction(TestAction::make('upload')->table()));

    expect($ids)->toContain($f->types['KK']->id, $f->types['AKTA']->id, $f->types['IJAZAH']->id, $f->types['RAPOR']->id, $f->types['FOTO']->id, $f->types['BPJS']->id)
        ->not->toContain($f->types['SERTIFIKAT']->id)
        ->not->toContain($guardianType->id)
        ->not->toContain($inactive->id);
});

it('AC-36: shows the validation error on the form field', function () {
    $f = $this->f;

    $component = dokumenManager($f->s1)
        ->callAction(TestAction::make('upload')->table(), ['document_type_id' => $f->types['KK']->id, 'file' => dokumenPdf(), 'issued_on' => '2026-10-16'])
        ->assertHasFormErrors(['issued_on']);

    expect(dokumenSeeMessage($component, 'issued_on_future'))->toBeTrue()
        ->and(Document::query()->count())->toBe(0)
        ->and(dokumenPrivateFiles())->toBe([]);
});

it('AC-36: requires a file and a type', function () {
    dokumenManager($this->f->s1)
        ->callAction(TestAction::make('upload')->table(), ['document_type_id' => null, 'file' => null])
        ->assertHasFormErrors(['document_type_id', 'file']);

    expect(Document::query()->count())->toBe(0);
});

it('AC-36: shows the expiry field only for types with expiry', function () {
    $f = $this->f;

    dokumenManager($f->s1)
        ->mountAction(TestAction::make('upload')->table())
        ->set('mountedActions.0.data.document_type_id', $f->types['BPJS']->id)
        ->assertSchemaComponentVisible('expires_on')
        ->set('mountedActions.0.data.document_type_id', $f->types['KK']->id)
        ->assertSchemaComponentHidden('expires_on');
});

it('AC-36: describes the completeness of required documents', function () {
    $f = $this->f;
    dokumenCreate($f->s1, 'AKTA');

    $text = tagihanPlainText(dokumenManager($f->s1));

    expect($text)->toContain('Berkas wajib belum lengkap')
        ->toContain('Kartu Keluarga')->toContain('Akta kelahiran')
        ->not->toContain('Berkas wajib lengkap.');

    Document::query()->update(['status' => 'valid']);
    dokumenValid($f->s1, 'KK');

    $complete = tagihanPlainText(dokumenManager($f->s1));

    expect($complete)->toContain('Berkas wajib lengkap.')->not->toContain('Berkas wajib belum lengkap');
});

it('AC-36: names the reason for every missing document', function () {
    $f = $this->f;
    dokumenCreate($f->s1, 'AKTA');

    $text = tagihanPlainText(dokumenManager($f->s1));

    expect($text)->toContain(__('pondok.document.completeness_reasons.missing'))
        ->toContain(__('pondok.document.completeness_reasons.pending'))
        ->not->toContain('pondok.document.');
});

it('AC-36: lists only the documents of the owner', function () {
    $f = $this->f;
    $mine = dokumenCreate($f->s1, 'KK');
    $other = dokumenCreate($f->s2, 'KK');

    dokumenManager($f->s1)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$other]);
});

it('AC-36: hides the upload for an archived student', function () {
    $this->f->s1->delete();

    dokumenManager($this->f->s1)->assertActionHidden(TestAction::make('upload')->table());
});

it('AC-36: shows the tab on the student edit page and hides it when the module is off', function () {
    $this->f->s1->refresh();

    Livewire::test(EditStudent::class, ['record' => $this->f->s1->id])->assertSeeLivewire(DocumentsRelationManager::class);

    Setting::set('modules.dokumen.enabled', false);

    Livewire::test(EditStudent::class, ['record' => $this->f->s1->id])->assertDontSeeLivewire(DocumentsRelationManager::class);
    $this->get("/admin/students/{$this->f->s1->id}/edit")->assertOk();
});

it('AC-37: manages guardian and staff documents', function () {
    $f = $this->f;
    $guardianType = dokumenType(['code' => 'WALIDOK', 'owner' => 'wali']);
    $staffType = dokumenType(['code' => 'STAFFDOK', 'owner' => 'staff']);

    $guardianIds = dokumenTypeOptions(dokumenManager($f->g1)->mountAction(TestAction::make('upload')->table()));
    $staffIds = dokumenTypeOptions(dokumenManager($f->t1)->mountAction(TestAction::make('upload')->table()));

    expect($guardianIds)->toContain($guardianType->id)->not->toContain($staffType->id)->not->toContain($f->types['KK']->id)
        ->and($staffIds)->toContain($staffType->id)->not->toContain($guardianType->id)->not->toContain($f->types['KK']->id);

    dokumenManager($f->g1)
        ->callAction(TestAction::make('upload')->table(), ['document_type_id' => $guardianType->id, 'file' => dokumenPdf('ktp.pdf'), 'number' => '3201000000000009'])
        ->assertHasNoFormErrors();
    dokumenManager($f->t1)
        ->callAction(TestAction::make('upload')->table(), ['document_type_id' => $staffType->id, 'file' => dokumenPdf('sk.pdf')])
        ->assertHasNoFormErrors();

    $guardianDocument = Document::query()->where('documentable_type', 'guardian')->sole();
    $staffDocument = Document::query()->where('documentable_type', 'staff')->sole();

    expect($guardianDocument->documentable_id)->toBe($f->g1->id)
        ->and($guardianDocument->file_path)->toStartWith("documents/guardian/{$f->g1->id}/")
        ->and($staffDocument->documentable_id)->toBe($f->t1->id)
        ->and($staffDocument->file_path)->toStartWith("documents/staff/{$f->t1->id}/");

    dokumenManager($f->g1)->assertCanSeeTableRecords([$guardianDocument])->assertCanNotSeeTableRecords([$staffDocument]);
});

it('AC-37: shows the tab for all three owners and hides it when the module is off', function () {
    $f = $this->f;

    expect(DocumentsRelationManager::canViewForRecord($f->s1, EditStudent::class))->toBeTrue()
        ->and(DocumentsRelationManager::canViewForRecord($f->g1, EditGuardian::class))->toBeTrue()
        ->and(DocumentsRelationManager::canViewForRecord($f->t1, EditStaff::class))->toBeTrue();

    Setting::set('modules.dokumen.enabled', false);

    expect(DocumentsRelationManager::canViewForRecord($f->s1, EditStudent::class))->toBeFalse()
        ->and(DocumentsRelationManager::canViewForRecord($f->g1, EditGuardian::class))->toBeFalse()
        ->and(DocumentsRelationManager::canViewForRecord($f->t1, EditStaff::class))->toBeFalse();

    $this->get("/admin/students/{$f->s1->id}/edit")->assertOk();
    $this->get("/admin/guardians/{$f->g1->id}/edit")->assertOk();
    $this->get("/admin/staff/{$f->t1->id}/edit")->assertOk();
});

it('AC-37: hides the tab from users who may not view documents', function () {
    $this->actingAs(keuanganUser('bendahara'));

    expect(DocumentsRelationManager::canViewForRecord($this->f->s1, EditStudent::class))->toBeFalse();
});

it('AC-37: hides guardian documents outside the unit scope', function () {
    $f = $this->f;
    $walia = dokumenType(['code' => 'WALIDOK', 'owner' => 'wali']);
    $alone = Guardian::factory()->create();
    $linked = Guardian::factory()->create();
    $linked->students()->attach($f->s2->id, ['relation' => 'ibu', 'is_billing_contact' => false]);
    $scoped = keuanganUser('admin-sekolah', ['SLTP']);
    $this->actingAs($scoped);

    expect(DocumentsRelationManager::canViewForRecord($f->g1, EditGuardian::class))->toBeFalse()
        ->and(DocumentsRelationManager::canViewForRecord($alone, EditGuardian::class))->toBeFalse()
        ->and(DocumentsRelationManager::canViewForRecord($linked, EditGuardian::class))->toBeTrue()
        ->and(DocumentsRelationManager::canViewForRecord($f->s2, EditStudent::class))->toBeTrue()
        ->and(DocumentsRelationManager::canViewForRecord($f->t1, EditStaff::class))->toBeTrue();

    dokumenManager($linked)
        ->callAction(TestAction::make('upload')->table(), ['document_type_id' => $walia->id, 'file' => dokumenPdf('ktp.pdf')])
        ->assertHasNoFormErrors();

    expect(Document::withoutUnitScope()->where('documentable_type', 'guardian')->sole()->documentable_id)->toBe($linked->id);
});

it('AC-37: lets an unscoped admin see the tab for a guardian without students', function () {
    $alone = Guardian::factory()->create();

    expect(DocumentsRelationManager::canViewForRecord($alone, EditGuardian::class))->toBeTrue();
});

it('AC-38: hides confidential types without permission', function () {
    $f = $this->f;
    $creator = dashboardUserWith(['view-any:document', 'view:document', 'create:document']);
    $this->actingAs($creator);

    $ids = dokumenTypeOptions(dokumenManager($f->s1)->mountAction(TestAction::make('upload')->table()));

    expect($ids)->toContain($f->types['IJAZAH']->id, $f->types['FOTO']->id)
        ->not->toContain($f->types['KK']->id)
        ->not->toContain($f->types['AKTA']->id);
});

it('AC-38: rejects a confidential type sent directly', function () {
    $f = $this->f;
    $creator = dashboardUserWith(['view-any:document', 'view:document', 'create:document']);
    $this->actingAs($creator);

    $component = dokumenManager($f->s1)
        ->callAction(TestAction::make('upload')->table(), ['document_type_id' => $f->types['KK']->id, 'file' => dokumenPdf()])
        ->assertHasFormErrors(['document_type_id']);

    expect(Document::query()->count())->toBe(0)
        ->and(dokumenPrivateFiles())->toBe([])
        ->and(dokumenSeeMessage($component, 'confidential_not_allowed'))->toBeTrue();
});

it('AC-38: hides and refuses a new version of a confidential document', function () {
    $f = $this->f;
    $kk = dokumenCreate($f->s1, 'KK');
    $creator = dashboardUserWith(['view-any:document', 'view:document', 'create:document']);
    $this->actingAs($creator);
    $files = dokumenPrivateFiles();

    dokumenManager($f->s1)
        ->assertActionHidden(TestAction::make('replace')->table($kk))
        ->mountAction(TestAction::make('replace')->table($kk))
        ->assertActionNotMounted(TestAction::make('replace')->table($kk));

    try {
        dokumenManager($f->s1)->callAction(TestAction::make('replace')->table($kk), ['file' => dokumenPdf('kk-baru.pdf')]);
    } catch (Throwable) {
    }

    expect(Document::query()->count())->toBe(1)
        ->and($kk->fresh()->replaced_by_id)->toBeNull()
        ->and(dokumenPrivateFiles())->toBe($files);
});

it('AC-38: lets a user with the confidential permission upload confidential types', function () {
    $f = $this->f;
    $user = dashboardUserWith(['view-any:document', 'view:document', 'create:document', 'view-confidential:document']);
    $this->actingAs($user);

    $ids = dokumenTypeOptions(dokumenManager($f->s1)->mountAction(TestAction::make('upload')->table()));

    expect($ids)->toContain($f->types['KK']->id);

    dokumenManager($f->s1)
        ->callAction(TestAction::make('upload')->table(), ['document_type_id' => $f->types['KK']->id, 'file' => dokumenPdf()])
        ->assertHasNoFormErrors();

    expect(Document::query()->sole()->uploaded_by)->toBe($user->id);
});

it('AC-38: hides the upload from users without the create permission', function () {
    $this->actingAs(keuanganUser('kesantrian'));

    dokumenManager($this->f->s1)->assertActionHidden(TestAction::make('upload')->table());
});
