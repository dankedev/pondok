<?php

use App\Filament\Resources\DocumentTypes\Pages\ManageDocumentTypes;
use App\Models\AdmissionRequirement;
use App\Models\DocumentType;
use App\Models\Setting;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = dokumenFixture();
    $this->actingAs($this->f->admin);
});

it('AC-33: manages document types', function () {
    Livewire::test(ManageDocumentTypes::class)
        ->assertActionVisible('create')
        ->callAction('create', ['code' => ' kip ', 'name' => 'Kartu Indonesia Pintar', 'owner' => 'santri', 'is_required' => false, 'has_expiry' => true, 'is_confidential' => false, 'is_active' => true])
        ->assertHasNoFormErrors();

    expect(DocumentType::query()->where('code', 'KIP')->sole())
        ->name->toBe('Kartu Indonesia Pintar')->owner->toBe('santri')->has_expiry->toBeTrue();
});

it('AC-33: shows a taken code as an error on the code field', function () {
    $count = DocumentType::query()->count();

    $component = Livewire::test(ManageDocumentTypes::class)
        ->callAction('create', ['code' => 'KK', 'name' => 'KK ganda', 'owner' => 'santri', 'is_active' => true])
        ->assertHasFormErrors(['code']);

    expect(DocumentType::query()->count())->toBe($count)
        ->and(dokumenSeeMessage($component, 'code_taken'))->toBeTrue();
});

it('AC-33: edits a type and keeps the code locked', function () {
    $ijazah = $this->f->types['IJAZAH'];

    Livewire::test(ManageDocumentTypes::class)
        ->mountAction(TestAction::make('edit')->table($ijazah))
        ->assertSchemaComponentExists('code', checkComponentUsing: fn ($component): bool => $component->isDisabled())
        ->assertSchemaComponentExists('name', checkComponentUsing: fn ($component): bool => ! $component->isDisabled());

    Livewire::test(ManageDocumentTypes::class)
        ->callAction(TestAction::make('edit')->table($ijazah), ['name' => 'Ijazah terakhir', 'owner' => 'santri', 'is_confidential' => true, 'is_active' => true])
        ->assertHasNoFormErrors();

    expect($ijazah->fresh())->code->toBe('IJAZAH')->name->toBe('Ijazah terakhir')->is_confidential->toBeTrue();
});

it('AC-33: hides delete for types that are in use and deletes the others', function () {
    $f = $this->f;
    dokumenCreate($f->s1, 'KK');
    $requirement = dokumenType(['code' => 'SYARAT', 'owner' => 'santri']);
    AdmissionRequirement::factory()->create(['document_type_id' => $requirement->id, 'unit_id' => null]);
    $free = dokumenType(['code' => 'BEBAS', 'owner' => 'santri']);

    Livewire::test(ManageDocumentTypes::class)
        ->assertActionHidden(TestAction::make('delete')->table($f->types['KK']))
        ->assertActionHidden(TestAction::make('delete')->table($requirement))
        ->assertActionVisible(TestAction::make('delete')->table($free))
        ->callAction(TestAction::make('delete')->table($free));

    expect(DocumentType::query()->find($free->id))->toBeNull()
        ->and(DocumentType::query()->find($requirement->id))->not->toBeNull()
        ->and(DocumentType::query()->where('code', 'KK')->exists())->toBeTrue();
});

it('AC-33: has no bulk delete', function () {
    $table = Livewire::test(ManageDocumentTypes::class)->instance()->getTable();

    expect($table->getBulkAction('delete'))->toBeNull()
        ->and(array_keys($table->getFlatBulkActions()))->not->toContain('delete');
});

it('AC-33: disables the owner of a used type on edit', function () {
    dokumenCreate($this->f->s1, 'KK');

    Livewire::test(ManageDocumentTypes::class)
        ->mountAction(TestAction::make('edit')->table($this->f->types['KK']))
        ->assertSchemaComponentExists('owner', checkComponentUsing: fn ($component): bool => $component->isDisabled());

    Livewire::test(ManageDocumentTypes::class)
        ->mountAction(TestAction::make('edit')->table($this->f->types['FOTO']))
        ->assertSchemaComponentExists('owner', checkComponentUsing: fn ($component): bool => ! $component->isDisabled());
});

it('AC-33: shows the required toggle only for student types', function () {
    Livewire::test(ManageDocumentTypes::class)
        ->mountAction('create')
        ->set('mountedActions.0.data.owner', 'santri')
        ->assertSchemaComponentVisible('is_required')
        ->set('mountedActions.0.data.owner', 'wali')
        ->assertSchemaComponentHidden('is_required')
        ->set('mountedActions.0.data.owner', 'staff')
        ->assertSchemaComponentHidden('is_required');
});

it('AC-33: lists the types with the right labels and counts', function () {
    dokumenCreate($this->f->s1, 'KK');
    dokumenCreate($this->f->s2, 'KK');

    $component = Livewire::test(ManageDocumentTypes::class)->assertCanSeeTableRecords(DocumentType::query()->get());
    $text = tagihanPlainText($component);

    expect($text)->toContain('Kartu Keluarga')->toContain('Wajib')->toContain('Opsional')
        ->not->toContain('pondok.document_type.');

    $component->assertTableColumnStateSet('documents_count', 2, $this->f->types['KK'])
        ->assertTableColumnStateSet('documents_count', 0, $this->f->types['FOTO']);
});

it('AC-33: locks types used outside the unit scope', function () {
    $f = $this->f;
    dokumenCreate($f->s1, 'IJAZAH');
    $this->actingAs(keuanganUser('admin-sekolah', ['SLTP']));

    $component = Livewire::test(ManageDocumentTypes::class)
        ->assertActionHidden(TestAction::make('delete')->table($f->types['IJAZAH']))
        ->assertActionVisible(TestAction::make('delete')->table($f->types['FOTO']))
        ->assertTableColumnStateSet('documents_count', 1, $f->types['IJAZAH'])
        ->mountAction(TestAction::make('edit')->table($f->types['IJAZAH']))
        ->assertSchemaComponentExists('owner', checkComponentUsing: fn ($component): bool => $component->isDisabled());

    expect($component)->not->toBeNull();

    Livewire::test(ManageDocumentTypes::class)
        ->mountAction(TestAction::make('delete')->table($f->types['IJAZAH']))
        ->assertActionNotMounted(TestAction::make('delete')->table($f->types['IJAZAH']));

    expect(DocumentType::query()->where('code', 'IJAZAH')->exists())->toBeTrue();
});

it('AC-33: refuses other roles', function (string $role) {
    $this->actingAs(keuanganUser($role));

    Livewire::test(ManageDocumentTypes::class)->assertForbidden();
})->with(['kesantrian', 'bendahara', 'admin-psb', 'musyrif']);

it('AC-33: refuses the page when the module is off', function () {
    Setting::set('modules.dokumen.enabled', false);

    Livewire::test(ManageDocumentTypes::class)->assertForbidden();
});
