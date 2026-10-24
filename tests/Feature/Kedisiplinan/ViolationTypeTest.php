<?php

use App\Actions\Kedisiplinan\RecordStudentViolation;
use App\Actions\Kedisiplinan\SaveViolationType;
use App\Filament\Resources\StudentViolations\Pages\CreateStudentViolation;
use App\Filament\Resources\ViolationTypes\Pages\CreateViolationType;
use App\Filament\Resources\ViolationTypes\Pages\EditViolationType;
use App\Filament\Resources\ViolationTypes\Pages\ListViolationTypes;
use App\Models\ViolationType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = disiplinFixture();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function disiplinTypeData(array $overrides = []): array
{
    return ['name' => 'Merokok', 'category' => 'berat', 'points' => 60, 'is_active' => true, ...$overrides];
}

it('AC-9: saves a new violation type and updates one without clashing with its own name', function () {
    $f = $this->f;
    $this->actingAs($f->kes);

    $type = app(SaveViolationType::class)->handle($f->kes, disiplinTypeData());

    expect($type->name)->toBe('Merokok')
        ->and($type->category->value)->toBe('berat')
        ->and($type->points)->toBe(60)
        ->and($type->is_active)->toBeTrue();

    $updated = app(SaveViolationType::class)->handle($f->kes, disiplinTypeData(['points' => 70]), $type);

    expect($updated->points)->toBe(70)->and(ViolationType::query()->where('name', 'Merokok')->count())->toBe(1);
});

it('AC-9: rejects a duplicate name, a category outside the enum, and points outside 1 to 1000', function () {
    $f = $this->f;
    $this->actingAs($f->kes);
    $before = ViolationType::query()->count();

    $cases = [
        'nama ganda' => [['name' => $f->ringan->name], 'name'],
        'nama kosong' => [['name' => ''], 'name'],
        'kategori tidak dikenal' => [['category' => 'kritis'], 'category'],
        'poin 0' => [['points' => 0], 'points'],
        'poin 1001' => [['points' => 1001], 'points'],
        'poin negatif' => [['points' => -5], 'points'],
    ];

    foreach ($cases as $label => [$overrides, $field]) {
        try {
            app(SaveViolationType::class)->handle($f->kes, disiplinTypeData($overrides));
            $this->fail("{$label}: ValidationException diharapkan");
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($field, $label);
        }
    }

    foreach ([1, 1000] as $points) {
        expect(app(SaveViolationType::class)->handle($f->kes, disiplinTypeData(['name' => "Batas {$points}", 'points' => $points]))->points)->toBe($points);
    }

    expect(ViolationType::query()->count())->toBe($before + 2);
});

it('AC-9: refuses musyrif, admin-sekolah and other roles to save a violation type', function () {
    $f = $this->f;

    foreach ([$f->musyrif, $f->adminSekolah, $f->waliKelas] as $user) {
        $this->actingAs($user);

        expect(fn () => app(SaveViolationType::class)->handle($user, disiplinTypeData()))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SaveViolationType::class)->handle($user, disiplinTypeData(['points' => 1]), $f->ringan))->toThrow(AuthorizationException::class);
    }

    expect(ViolationType::query()->count())->toBe(3)->and($f->ringan->fresh()->points)->toBe(5);
});

it('AC-9: deactivating a type keeps the old records and removes the type from the choices and from new records', function () {
    $f = $this->f;
    $record = disiplinRaw($f, $f->s1, $f->ringan);
    $this->actingAs($f->kes);

    app(SaveViolationType::class)->handle($f->kes, disiplinTypeData(['name' => $f->ringan->name, 'category' => 'ringan', 'points' => 5, 'is_active' => false]), $f->ringan);

    expect($f->ringan->fresh()->is_active)->toBeFalse()
        ->and($record->fresh()->violation_type_id)->toBe($f->ringan->id)
        ->and($record->fresh()->points)->toBe(5)
        ->and(disiplinOptions(Livewire::test(CreateStudentViolation::class), 'violation_type_id'))->not->toContain($f->ringan->id)->toContain($f->sedang->id);

    tahfidzRejected(fn () => app(RecordStudentViolation::class)->handle($f->kes, disiplinData($f)), 'violation_type_id');
});

it('AC-9: creates, edits and filters types through the Filament pages, and shows a duplicate name as a form error', function () {
    $f = $this->f;
    $f->berat->forceFill(['is_active' => false])->save();
    $this->actingAs($f->kes);

    Livewire::test(CreateViolationType::class)
        ->fillForm(['name' => 'Merokok', 'category' => 'berat', 'points' => 60, 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateViolationType::class)
        ->fillForm(['name' => 'Merokok', 'category' => 'berat', 'points' => 60, 'is_active' => true])
        ->call('create')
        ->assertHasFormErrors(['name']);

    Livewire::test(CreateViolationType::class)
        ->fillForm(['name' => 'Nol poin', 'category' => 'ringan', 'points' => 0, 'is_active' => true])
        ->call('create')
        ->assertHasFormErrors(['points']);

    $smoking = ViolationType::query()->where('name', 'Merokok')->firstOrFail();

    Livewire::test(EditViolationType::class, ['record' => $smoking->getKey()])
        ->fillForm(['points' => 65])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($smoking->fresh()->points)->toBe(65);

    Livewire::test(ListViolationTypes::class)
        ->assertCanSeeTableRecords([$f->ringan, $f->sedang, $f->berat, $smoking])
        ->filterTable('category', 'sedang')
        ->assertCanSeeTableRecords([$f->sedang])->assertCanNotSeeTableRecords([$f->ringan, $smoking])
        ->resetTableFilters()
        ->filterTable('is_active', false)
        ->assertCanSeeTableRecords([$f->berat])->assertCanNotSeeTableRecords([$f->ringan, $f->sedang])
        ->assertTableActionDoesNotExist('delete');
});
