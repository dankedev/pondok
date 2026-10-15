<?php

use App\Actions\Dokumen\SaveDocumentType;
use App\Models\AdmissionRequirement;
use App\Models\DocumentType;

beforeEach(function () {
    $this->f = dokumenFixture();
});

/**
 * Data masukan yang valid; untuk edit, nilai jenis yang ada.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dokumenTypeData(array $overrides = [], ?DocumentType $type = null): array
{
    $base = $type === null
        ? ['code' => 'NEWT', 'name' => 'Jenis Baru', 'owner' => 'santri']
        : $type->only(['code', 'name', 'owner', 'is_required', 'has_expiry', 'is_confidential', 'is_active']);

    return [...$base, ...$overrides];
}

it('AC-8: creates a document type', function () {
    $type = app(SaveDocumentType::class)->handle(['code' => ' kip ', 'name' => 'Kartu Indonesia Pintar', 'owner' => 'santri']);

    expect($type->fresh())
        ->code->toBe('KIP')->name->toBe('Kartu Indonesia Pintar')->owner->toBe('santri')
        ->is_required->toBeFalse()->has_expiry->toBeFalse()->is_confidential->toBeFalse()->is_active->toBeTrue();
});

it('AC-8: creates a required student type with the given flags', function () {
    $type = app(SaveDocumentType::class)->handle(dokumenTypeData(['code' => 'REQ1', 'is_required' => true, 'has_expiry' => true, 'is_confidential' => true, 'is_active' => false]));

    expect($type->fresh())->is_required->toBeTrue()->has_expiry->toBeTrue()->is_confidential->toBeTrue()->is_active->toBeFalse();
});

it('AC-8: trims the name', function () {
    $type = app(SaveDocumentType::class)->handle(dokumenTypeData(['name' => '  Surat Pindah  ']));

    expect($type->fresh()->name)->toBe('Surat Pindah');
});

it('AC-9: rejects invalid document types', function (array $overrides, string $reason, string $field) {
    $count = DocumentType::query()->count();

    dokumenAssertRejected(fn () => app(SaveDocumentType::class)->handle(dokumenTypeData($overrides)), $reason, $field);

    expect(DocumentType::query()->count())->toBe($count);
})->with([
    'code too short' => [['code' => 'K'], 'code_format', 'code'],
    'code with hyphen' => [['code' => 'KK-BARU'], 'code_format', 'code'],
    'code too long' => [['code' => str_repeat('A', 31)], 'code_format', 'code'],
    'code with space inside' => [['code' => 'KK BARU'], 'code_format', 'code'],
    'code taken' => [['code' => 'KK'], 'code_taken', 'code'],
    'code taken ignoring case and spaces' => [['code' => ' akta '], 'code_taken', 'code'],
    'blank name' => [['name' => '  '], 'name_required', 'name'],
    'name too long' => [['name' => str_repeat('a', 256)], 'name_required', 'name'],
    'unknown owner' => [['owner' => 'ortu'], 'owner_invalid', 'owner'],
    'required for guardian' => [['owner' => 'wali', 'is_required' => true], 'required_only_for_student', 'is_required'],
    'required for staff' => [['owner' => 'staff', 'is_required' => true], 'required_only_for_student', 'is_required'],
    'required for achievement' => [['owner' => 'prestasi', 'is_required' => true], 'required_only_for_student', 'is_required'],
]);

it('AC-9: accepts a 30 character code', function () {
    $type = app(SaveDocumentType::class)->handle(dokumenTypeData(['code' => str_repeat('A', 29).'_']));

    expect($type->fresh()->code)->toBe(str_repeat('A', 29).'_');
});

it('AC-10: locks code and used owners on edit', function () {
    $ijazah = $this->f->types['IJAZAH'];

    $saved = app(SaveDocumentType::class)->handle(dokumenTypeData(['name' => 'Ijazah terakhir', 'is_confidential' => true], $ijazah), $ijazah);

    expect($saved->fresh())->name->toBe('Ijazah terakhir')->is_confidential->toBeTrue()->code->toBe('IJAZAH');

    dokumenAssertRejected(fn () => app(SaveDocumentType::class)->handle(dokumenTypeData(['code' => 'IJAZAH2'], $ijazah), $ijazah), 'code_locked', 'code');

    expect($ijazah->fresh()->code)->toBe('IJAZAH');
});

it('AC-10: keeps the code when it is sent again in another case', function () {
    $foto = $this->f->types['FOTO'];

    $saved = app(SaveDocumentType::class)->handle(dokumenTypeData(['code' => 'foto', 'name' => 'Pas foto 3x4'], $foto), $foto);

    expect($saved->fresh())->code->toBe('FOTO')->name->toBe('Pas foto 3x4');
});

it('AC-10: changes the owner of an unused type', function () {
    $foto = $this->f->types['FOTO'];

    app(SaveDocumentType::class)->handle(dokumenTypeData(['owner' => 'wali'], $foto), $foto);

    expect($foto->fresh()->owner)->toBe('wali');
});

it('AC-10: refuses to change the owner of a type that has documents', function () {
    $kk = $this->f->types['KK'];
    dokumenCreate($this->f->s1, 'KK');

    dokumenAssertRejected(fn () => app(SaveDocumentType::class)->handle(dokumenTypeData(['owner' => 'wali', 'is_required' => false], $kk), $kk), 'owner_locked', 'owner');

    expect($kk->fresh()->owner)->toBe('santri');
});

it('AC-10: refuses to change the owner of a type used by an admission requirement', function () {
    $type = dokumenType(['owner' => 'santri']);
    AdmissionRequirement::factory()->create(['document_type_id' => $type->id, 'unit_id' => null]);

    dokumenAssertRejected(fn () => app(SaveDocumentType::class)->handle(dokumenTypeData(['owner' => 'wali'], $type), $type), 'owner_locked', 'owner');

    expect($type->fresh()->owner)->toBe('santri');
});

it('AC-10: allows saving a used type when the owner stays the same', function () {
    $kk = $this->f->types['KK'];
    dokumenCreate($this->f->s1, 'KK');

    app(SaveDocumentType::class)->handle(dokumenTypeData(['name' => 'KK keluarga'], $kk), $kk);

    expect($kk->fresh()->name)->toBe('KK keluarga');
});

it('AC-10: counts documents of units outside the scope when locking the owner', function () {
    dokumenCreate($this->f->s1, 'IJAZAH');
    $ijazah = $this->f->types['IJAZAH'];
    $this->actingAs(keuanganUser('admin-sekolah', ['SLTP']));

    dokumenAssertRejected(fn () => app(SaveDocumentType::class)->handle(dokumenTypeData(['owner' => 'wali'], $ijazah), $ijazah), 'owner_locked', 'owner');

    expect($ijazah->fresh()->owner)->toBe('santri');
});
