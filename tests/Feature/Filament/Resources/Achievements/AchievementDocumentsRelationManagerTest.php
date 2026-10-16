<?php

use App\Filament\Resources\Achievements\Pages\EditAchievement;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Resources\Documents\RelationManagers\DocumentsRelationManager;
use App\Models\Achievement;
use App\Models\Document;
use App\Models\Setting;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = prestasiFixture();
});

/**
 * Relation manager dokumen untuk prestasi (halaman edit prestasi).
 */
function prestasiDocumentManager(Achievement $achievement): Testable
{
    return Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $achievement, 'pageClass' => EditAchievement::class]);
}

it('AC-28: manages certificates and photos of an achievement', function () {
    $f = $this->f;
    $p1 = prestasiCreate($f, [$f->s2]);
    $this->actingAs($f->k);

    expect(DocumentsRelationManager::canViewForRecord($p1, EditAchievement::class))->toBeTrue();

    $component = prestasiDocumentManager($p1)->assertSuccessful()->mountAction(TestAction::make('upload')->table());
    $ids = [];
    $component->assertSchemaComponentExists('document_type_id', checkComponentUsing: function ($select) use (&$ids): bool {
        $ids = array_map('intval', array_keys($select->getOptions()));

        return true;
    });

    expect($ids)->toEqualCanonicalizing([$f->types['SERTIFIKAT']->id, $f->types['FOTO_PRESTASI']->id]);

    prestasiDocumentManager($p1)
        ->callAction(TestAction::make('upload')->table(), ['document_type_id' => $f->types['SERTIFIKAT']->id, 'file' => dokumenPdf('sertifikat.pdf')])
        ->assertHasNoFormErrors()
        ->assertNotified();

    $document = Document::withoutUnitScope()->sole();

    expect($document->documentable_type)->toBe('achievement')
        ->and($document->documentable_id)->toBe($p1->id)
        ->and($document->uploaded_by)->toBe($f->k->id)
        ->and(Storage::disk('private')->exists($document->file_path))->toBeTrue()
        ->and(Storage::disk('public')->allFiles())->toBe([]);

    prestasiDocumentManager($p1)->assertCanSeeTableRecords([$document]);
});

it('AC-28: hides the documents tab when the dokumen module is off', function () {
    $f = $this->f;
    $p1 = prestasiCreate($f, [$f->s2]);
    Setting::set('modules.dokumen.enabled', false);
    $this->actingAs($f->k);

    expect(DocumentsRelationManager::canViewForRecord($p1, EditAchievement::class))->toBeFalse();

    $this->get("/admin/achievements/{$p1->id}/edit")->assertOk();
});

it('AC-28: shows the documents tab on the edit page', function () {
    $f = $this->f;
    $p1 = prestasiCreate($f, [$f->s2]);
    $this->actingAs($f->k);

    Livewire::test(EditAchievement::class, ['record' => $p1->getRouteKey()])->assertSeeLivewire(DocumentsRelationManager::class);
});

it('AC-28: keeps achievement documents out of the tab of users who cannot view the achievement', function () {
    $f = $this->f;
    $p3 = prestasiCreate($f, [$f->s1]);
    $this->actingAs($f->c);

    expect(DocumentsRelationManager::canViewForRecord($p3, EditAchievement::class))->toBeFalse();
});

it('AC-28: lists achievement documents in the document queue', function () {
    $f = $this->f;
    $p1 = prestasiCreate($f, [$f->s2], ['title' => 'Juara 1 MTQ Tahfidz 10 Juz']);
    $other = prestasiCreate($f, [$f->s2], ['title' => 'Lomba pidato']);
    $document = prestasiDocument($f, $p1);
    $otherDocument = prestasiDocument($f, $other);
    $this->actingAs($f->a);

    $component = Livewire::test(ListDocuments::class)->assertCanSeeTableRecords([$document, $otherDocument]);

    expect(tagihanPlainText($component))->toContain('Juara 1 MTQ Tahfidz 10 Juz')->toContain(__('pondok.document.owner_types.achievement'));

    Livewire::test(ListDocuments::class)
        ->searchTable('Juara 1 MTQ')
        ->assertCanSeeTableRecords([$document])
        ->assertCanNotSeeTableRecords([$otherDocument]);
});

it('AC-28: hides achievement documents of other units in the queue', function () {
    $f = $this->f;
    $own = prestasiDocument($f, prestasiCreate($f, [$f->s2]));
    $other = prestasiDocument($f, prestasiCreate($f, [$f->s1]));
    $this->actingAs($f->a2);

    Livewire::test(ListDocuments::class)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$other]);
});
