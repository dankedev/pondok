<?php

use App\Actions\Psb\SaveAdmissionRequirement;
use App\Models\AdmissionRequirement;
use App\Models\ApplicantDocument;
use App\Models\DocumentType;
use Database\Seeders\RolePermissionSeeder;

function psbSaveRequirement(object $f, array $data = [], ?AdmissionRequirement $requirement = null): AdmissionRequirement
{
    return app(SaveAdmissionRequirement::class)->handle(psbRequirementData($f, $data), $requirement);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function psbRequirement(object $f, ?string $unit, string $type, string $name, array $attributes = []): AdmissionRequirement
{
    return AdmissionRequirement::factory()->create([
        'unit_id' => $unit === null ? null : $f->{$unit}->id,
        'document_type_id' => $f->types[$type]->id,
        'name' => $name,
        ...$attributes,
    ]);
}

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->f = psbFixture();
    $this->actingAs(psbUser('admin-psb'));
});

it('AC-14: creates a requirement with normalized values', function () {
    $requirement = psbSaveRequirement($this->f, ['name' => ' Kartu Keluarga ', 'sort_order' => '2']);

    expect($requirement->fresh())
        ->unit_id->toBe($this->f->sd->id)
        ->document_type_id->toBe($this->f->types['KK']->id)
        ->name->toBe('Kartu Keluarga')
        ->sort_order->toBe(2)
        ->is_required->toBeTrue();
});

it('AC-14: stores an empty unit as all units', function (mixed $unit) {
    expect(psbSaveRequirement($this->f, ['unit_id' => $unit])->fresh()->unit_id)->toBeNull();
})->with(['empty string' => [''], 'null' => [null]]);

it('AC-14: defaults an empty sort order to zero and keeps is_required false', function (mixed $sortOrder) {
    $requirement = psbSaveRequirement($this->f, ['sort_order' => $sortOrder, 'is_required' => false]);

    expect($requirement->fresh())->sort_order->toBe(0)->is_required->toBeFalse();
})->with(['empty string' => [''], 'null' => [null]]);

it('AC-15: accepts the sort order bounds 0 and 255', function (int $sortOrder) {
    expect(psbSaveRequirement($this->f, ['sort_order' => $sortOrder])->fresh()->sort_order)->toBe($sortOrder);
})->with([0, 255]);

it('AC-15: rejects an invalid requirement', function (Closure $overrides, string $reason, string $field) {
    $f = $this->f;
    $overrides = $overrides($f);

    psbAssertRejected(fn () => psbSaveRequirement($f, $overrides), $reason, $field);

    expect(AdmissionRequirement::query()->count())->toBe(0);
})->with([
    'unknown unit' => [fn () => ['unit_id' => 999], 'unit_not_found', 'unit_id'],
    'unknown document type' => [fn () => ['document_type_id' => 999], 'document_type_not_found', 'document_type_id'],
    'achievement document type' => [fn (object $f) => ['document_type_id' => $f->types['SERTIFIKAT']->id], 'document_type_not_for_student', 'document_type_id'],
    'inactive document type' => [fn () => ['document_type_id' => DocumentType::factory()->create(['owner' => 'santri', 'is_active' => false])->id], 'document_type_inactive', 'document_type_id'],
    'blank name' => [fn () => ['name' => '  '], 'name_required', 'name'],
    'negative sort order' => [fn () => ['sort_order' => -1], 'sort_order_invalid', 'sort_order'],
    'sort order above 255' => [fn () => ['sort_order' => 256], 'sort_order_invalid', 'sort_order'],
    'decimal sort order' => [fn () => ['sort_order' => '1.5'], 'sort_order_invalid', 'sort_order'],
]);

it('AC-15: keeps a document type that was deactivated after the requirement was created', function () {
    $requirement = psbSaveRequirement($this->f);
    $this->f->types['KK']->update(['is_active' => false]);

    $updated = psbSaveRequirement($this->f, ['name' => 'KK terbaru'], $requirement->fresh());

    expect($updated->fresh())->name->toBe('KK terbaru')->document_type_id->toBe($this->f->types['KK']->id);
});

it('AC-16: rejects a unit requirement beside an all-units one for the same document type', function () {
    psbSaveRequirement($this->f, ['unit_id' => null]);

    psbAssertRejected(fn () => psbSaveRequirement($this->f, ['unit_id' => $this->f->sd->id]), 'requirement_exists', 'document_type_id');

    expect(AdmissionRequirement::query()->count())->toBe(1);
});

it('AC-16: rejects an all-units requirement beside a unit one, and allows other units and types', function () {
    psbSaveRequirement($this->f, ['unit_id' => $this->f->sd->id]);

    psbAssertRejected(fn () => psbSaveRequirement($this->f, ['unit_id' => null]), 'requirement_exists', 'document_type_id');

    psbSaveRequirement($this->f, ['unit_id' => $this->f->sltp->id]);
    psbSaveRequirement($this->f, ['document_type_id' => $this->f->types['AKTA']->id, 'name' => 'Akta']);

    expect(AdmissionRequirement::query()->count())->toBe(3);
});

it('AC-16: rejects the same document type for the same unit', function () {
    psbSaveRequirement($this->f);

    psbAssertRejected(fn () => psbSaveRequirement($this->f, ['name' => 'KK lagi']), 'requirement_exists', 'document_type_id');
});

it('AC-16: saves a requirement again without clashing with itself', function () {
    $requirement = psbSaveRequirement($this->f);

    $updated = psbSaveRequirement($this->f, ['name' => 'KK terbaru', 'sort_order' => 5], $requirement);

    expect($updated->id)->toBe($requirement->id)->and($requirement->fresh())->name->toBe('KK terbaru')->sort_order->toBe(5)
        ->and(AdmissionRequirement::query()->count())->toBe(1);
});

it('AC-16: rejects editing a requirement into another existing document type for its unit', function () {
    psbSaveRequirement($this->f);
    $akta = psbSaveRequirement($this->f, ['document_type_id' => $this->f->types['AKTA']->id, 'name' => 'Akta']);

    psbAssertRejected(fn () => psbSaveRequirement($this->f, ['document_type_id' => $this->f->types['KK']->id, 'name' => 'Akta'], $akta), 'requirement_exists', 'document_type_id');

    expect($akta->fresh()->document_type_id)->toBe($this->f->types['AKTA']->id);
});

it('AC-17: limits a scoped user to the own units', function () {
    $this->actingAs(psbUser('admin-psb', ['SLTP']));

    psbAssertRejected(fn () => psbSaveRequirement($this->f, ['unit_id' => $this->f->sd->id]), 'unit_not_found', 'unit_id');
    psbAssertRejected(fn () => psbSaveRequirement($this->f, ['unit_id' => null]), 'all_units_requires_unscoped', 'unit_id');

    $requirement = psbSaveRequirement($this->f, ['unit_id' => $this->f->sltp->id]);

    expect($requirement->fresh()->unit_id)->toBe($this->f->sltp->id)->and(AdmissionRequirement::query()->count())->toBe(1);
});

it('AC-17: stops a scoped user from editing all-units requirements and other units', function () {
    $all = psbRequirement($this->f, null, 'KK', 'KK semua');
    $sd = psbRequirement($this->f, 'sd', 'AKTA', 'Akta SD');
    $this->actingAs(psbUser('admin-psb', ['SLTP']));

    psbAssertRejected(fn () => psbSaveRequirement($this->f, ['unit_id' => $this->f->sltp->id, 'name' => 'Diubah'], $all), 'all_units_requires_unscoped', 'unit_id');
    psbAssertRejected(fn () => psbSaveRequirement($this->f, ['unit_id' => $this->f->sltp->id, 'document_type_id' => $this->f->types['AKTA']->id, 'name' => 'Diubah'], $sd), 'unit_not_found', 'unit_id');

    expect($all->fresh()->name)->toBe('KK semua')->and($sd->fresh())->name->toBe('Akta SD')->unit_id->toBe($this->f->sd->id);
});

it('AC-17: rejects moving a requirement of the own unit to another unit', function () {
    $sltp = psbRequirement($this->f, 'sltp', 'KK', 'KK SLTP');
    $this->actingAs(psbUser('admin-psb', ['SLTP']));

    psbAssertRejected(fn () => psbSaveRequirement($this->f, ['unit_id' => $this->f->sd->id, 'name' => 'KK SLTP'], $sltp), 'unit_not_found', 'unit_id');

    expect($sltp->fresh()->unit_id)->toBe($this->f->sltp->id);
});

it('AC-17: lets an unscoped user, even without a login, create an all-units requirement', function () {
    auth()->logout();

    $requirement = psbSaveRequirement($this->f, ['unit_id' => null]);

    expect($requirement->fresh()->unit_id)->toBeNull();
});

it('AC-17: lets an unscoped login edit any unit', function () {
    $sd = psbRequirement($this->f, 'sd', 'KK', 'KK SD');

    psbSaveRequirement($this->f, ['unit_id' => $this->f->slta->id, 'name' => 'KK SLTA'], $sd);

    expect($sd->fresh())->unit_id->toBe($this->f->slta->id)->name->toBe('KK SLTA');
});

it('AC-18: lists the requirements of a unit in order, without other units', function () {
    psbRequirement($this->f, null, 'KK', 'Kartu Keluarga', ['sort_order' => 2]);
    psbRequirement($this->f, 'sltp', 'AKTA', 'Akta kelahiran', ['sort_order' => 1]);
    psbRequirement($this->f, 'sd', 'RAPOR', 'Rapor', ['sort_order' => 0]);
    psbRequirement($this->f, 'sltp', 'FOTO', 'Pas foto', ['sort_order' => 1]);

    expect(AdmissionRequirement::forUnit($this->f->sltp->id)->pluck('name')->all())->toBe(['Akta kelahiran', 'Pas foto', 'Kartu Keluarga']);
});

it('AC-18: lists the same requirements without a login and for a user scoped to another unit', function () {
    psbRequirement($this->f, null, 'KK', 'Kartu Keluarga', ['sort_order' => 2]);
    psbRequirement($this->f, 'sltp', 'AKTA', 'Akta kelahiran', ['sort_order' => 1]);
    psbRequirement($this->f, 'sd', 'RAPOR', 'Rapor', ['sort_order' => 0]);
    psbRequirement($this->f, 'sltp', 'FOTO', 'Pas foto', ['sort_order' => 1]);
    $expected = ['Akta kelahiran', 'Pas foto', 'Kartu Keluarga'];

    auth()->logout();
    expect(AdmissionRequirement::forUnit($this->f->sltp->id)->pluck('name')->all())->toBe($expected);

    $this->actingAs(psbUser('admin-psb', ['SD']));
    expect(AdmissionRequirement::forUnit($this->f->sltp->id)->pluck('name')->all())->toBe($expected);
});

it('AC-6 (F010): locks the document type and unit of a requirement in use', function (string $field) {
    $f = $this->f;
    $requirement = psbRequirement($f, 'sd', 'KK', 'Kartu Keluarga');
    ApplicantDocument::factory()->create(['admission_requirement_id' => $requirement->id]);
    $change = match ($field) {
        'document_type_id' => ['document_type_id' => $f->types['AKTA']->id],
        'unit_id' => ['unit_id' => $f->sltp->id],
    };

    pendaftaranAssertRejected(fn () => psbSaveRequirement($f, $change, $requirement), 'requirement_in_use', 'document_type_id');

    expect($requirement->fresh())->document_type_id->toBe($f->types['KK']->id)->unit_id->toBe($f->sd->id);
})->with(['document type' => ['document_type_id'], 'unit' => ['unit_id']]);

it('AC-6 (F010): lets a requirement in use change name, required flag and order', function () {
    $f = $this->f;
    $requirement = psbRequirement($f, 'sd', 'KK', 'Kartu Keluarga');
    ApplicantDocument::factory()->create(['admission_requirement_id' => $requirement->id]);

    psbSaveRequirement($f, ['name' => 'KK terbaru', 'is_required' => false, 'sort_order' => 9], $requirement);

    expect($requirement->fresh())->name->toBe('KK terbaru')->is_required->toBeFalse()->sort_order->toBe(9);
});

it('AC-6 (F010): lets a requirement without documents change its document type and unit', function () {
    $f = $this->f;
    $requirement = psbRequirement($f, 'sd', 'KK', 'Kartu Keluarga');

    psbSaveRequirement($f, ['document_type_id' => $f->types['AKTA']->id, 'unit_id' => $f->sltp->id], $requirement);

    expect($requirement->fresh())->document_type_id->toBe($f->types['AKTA']->id)->unit_id->toBe($f->sltp->id);
});
