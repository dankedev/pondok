<?php

use App\Filament\Resources\AdmissionRequirements\Pages\ManageAdmissionRequirements;
use App\Models\AdmissionRequirement;
use App\Models\DocumentType;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->f = psbFixture();
    $this->actingAs(psbUser('admin-psb'));
});

function psbRequirementFormData(object $f, array $overrides = []): array
{
    return [
        'unit_id' => $f->sd->id,
        'document_type_id' => $f->types['KK']->id,
        'name' => 'Kartu Keluarga',
        'is_required' => true,
        'sort_order' => 1,
        ...$overrides,
    ];
}

it('AC-28: creates a requirement from the modal', function () {
    Livewire::test(ManageAdmissionRequirements::class)
        ->assertActionVisible('create')
        ->callAction('create', psbRequirementFormData($this->f))
        ->assertHasNoFormErrors();

    expect(AdmissionRequirement::query()->sole())
        ->unit_id->toBe($this->f->sd->id)->document_type_id->toBe($this->f->types['KK']->id)->name->toBe('Kartu Keluarga')
        ->is_required->toBeTrue()->sort_order->toBe(1);
});

it('AC-28: fills an empty name from the chosen document type', function () {
    Livewire::test(ManageAdmissionRequirements::class)
        ->mountAction('create')
        ->set('mountedActions.0.data.document_type_id', $this->f->types['AKTA']->id)
        ->assertActionDataSet(['name' => 'Akta kelahiran']);
});

it('AC-28: keeps a name that was already typed when the document type changes', function () {
    Livewire::test(ManageAdmissionRequirements::class)
        ->mountAction('create')
        ->set('mountedActions.0.data.name', 'Salinan KK')
        ->set('mountedActions.0.data.document_type_id', $this->f->types['KK']->id)
        ->assertActionDataSet(['name' => 'Salinan KK']);
});

it('AC-28: shows the conflict error on the document type field', function () {
    AdmissionRequirement::factory()->create(['unit_id' => null, 'document_type_id' => $this->f->types['KK']->id, 'name' => 'KK semua']);

    Livewire::test(ManageAdmissionRequirements::class)
        ->assertActionVisible('create')
        ->callAction('create', psbRequirementFormData($this->f))
        ->assertHasFormErrors(['document_type_id']);

    expect(AdmissionRequirement::query()->count())->toBe(1);
});

it('AC-28: offers only active student document types', function () {
    $f = $this->f;
    $inactive = DocumentType::factory()->create(['code' => 'LAMA', 'name' => 'Lama', 'owner' => 'santri', 'is_active' => false]);

    Livewire::test(ManageAdmissionRequirements::class)
        ->mountAction('create')
        ->assertSchemaComponentExists('document_type_id', checkComponentUsing: function ($component) use ($f, $inactive): bool {
            $ids = array_keys($component->getOptions());

            return in_array($f->types['KK']->id, $ids, true)
                && ! in_array($f->types['SERTIFIKAT']->id, $ids, true)
                && ! in_array($inactive->id, $ids, true);
        });
});

it('AC-28: deletes a requirement after confirmation', function () {
    $requirement = AdmissionRequirement::factory()->create(['unit_id' => null, 'document_type_id' => $this->f->types['KK']->id]);

    Livewire::test(ManageAdmissionRequirements::class)
        ->assertActionVisible(TestAction::make('delete')->table($requirement))
        ->callAction(TestAction::make('delete')->table($requirement));

    expect(AdmissionRequirement::query()->count())->toBe(0);
});

it('AC-28: has no bulk delete action', function () {
    $table = Livewire::test(ManageAdmissionRequirements::class)->instance()->getTable();

    expect($table->getFlatBulkActions())->not->toHaveKey('delete')->and($table->getBulkAction('delete'))->toBeNull();
});

it('AC-28: shows all units, required labels, and the empty state', function () {
    expect(__('pondok.admission_requirement.empty'))->not->toBe('pondok.admission_requirement.empty');

    Livewire::test(ManageAdmissionRequirements::class)->assertSeeText(__('pondok.admission_requirement.empty'));

    $all = AdmissionRequirement::factory()->create(['unit_id' => null, 'document_type_id' => $this->f->types['KK']->id, 'name' => 'KK semua', 'is_required' => true]);
    $sd = AdmissionRequirement::factory()->create(['unit_id' => $this->f->sd->id, 'document_type_id' => $this->f->types['FOTO']->id, 'name' => 'Foto SD', 'is_required' => false]);

    Livewire::test(ManageAdmissionRequirements::class)
        ->assertCanSeeTableRecords([$all, $sd])
        ->assertSeeText('Semua jenjang')
        ->assertSeeText($this->f->sd->name)
        ->assertSeeText('Wajib')
        ->assertSeeText('Opsional');
});

it('AC-28: sorts requirements by sort order, then id', function () {
    $second = AdmissionRequirement::factory()->create(['unit_id' => null, 'document_type_id' => $this->f->types['KK']->id, 'sort_order' => 2]);
    $first = AdmissionRequirement::factory()->create(['unit_id' => null, 'document_type_id' => $this->f->types['AKTA']->id, 'sort_order' => 1]);
    $firstTie = AdmissionRequirement::factory()->create(['unit_id' => null, 'document_type_id' => $this->f->types['FOTO']->id, 'sort_order' => 1]);

    Livewire::test(ManageAdmissionRequirements::class)->assertCanSeeTableRecords([$first, $firstTie, $second], inOrder: true);
});
