<?php

use App\Filament\Resources\FeeOverrides\Pages\ManageFeeOverrides;
use App\Models\FeeOverride;
use App\Models\Scholarship;
use App\Models\Student;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->f = keringananFixture();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function keringananFormData(object $f, array $overrides = []): array
{
    $data = keringananOverrideData($f, $overrides);
    unset($data['applicant_id']);

    return $data;
}

it('AC-30: limits overrides and student options to the user units', function () {
    $f = $this->f;
    $sltpOverride = keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id]);
    $sdOverride = keringananOverride(['student_id' => $f->c->id, 'fee_type_id' => $f->spp->id]);
    $graduated = Student::factory()->create(['cohort_id' => $f->a->cohort_id, 'status' => 'lulus']);
    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    Livewire::test(ManageFeeOverrides::class)
        ->assertCanSeeTableRecords([$sltpOverride])
        ->assertCanNotSeeTableRecords([$sdOverride])
        ->mountAction('create')
        ->assertSchemaComponentExists('student_id', checkComponentUsing: function ($component) use ($f, $graduated): bool {
            $ids = array_map('intval', array_keys($component->getOptions()));
            sort($ids);

            return $ids === [$f->a->id, $f->b->id] && ! in_array($graduated->id, $ids, true) && ! in_array($f->c->id, $ids, true);
        });
});

it('AC-30: forbids a scoped user from editing or ending overrides of another unit', function () {
    $f = $this->f;
    $sdOverride = keringananOverride(['student_id' => $f->c->id, 'fee_type_id' => $f->spp->id, 'value' => 100000, 'valid_until' => null]);
    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    expect(fn () => Livewire::test(ManageFeeOverrides::class)
        ->callAction(TestAction::make('edit')->table($sdOverride), keringananFormData($f, ['student_id' => $f->c->id, 'value' => 1])))->toThrow(Exception::class);
    expect(fn () => Livewire::test(ManageFeeOverrides::class)
        ->callAction(TestAction::make('end')->table($sdOverride), ['end_on' => '2026-10-31']))->toThrow(Exception::class);

    expect($sdOverride->fresh()->value)->toBe(100000)
        ->and($sdOverride->fresh()->valid_until)->toBeNull();
});

it('AC-30: hides applicant-only overrides', function () {
    $f = $this->f;
    $applicantOnly = keringananOverride(['student_id' => null, 'applicant_id' => 77, 'fee_type_id' => $f->spp->id]);
    $student = keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id]);
    $this->actingAs(keuanganUser('super-admin'));

    Livewire::test(ManageFeeOverrides::class)
        ->assertCanSeeTableRecords([$student])
        ->assertCanNotSeeTableRecords([$applicantOnly]);
});

it('AC-31: creates an override from the panel with scholarship defaults', function () {
    $f = $this->f;
    $scholarship = Scholarship::factory()->create(['mode' => 'persen', 'default_value' => 50]);
    $user = keuanganUser('bendahara');
    $this->actingAs($user);

    Livewire::test(ManageFeeOverrides::class)
        ->mountAction('create')
        ->set('mountedActions.0.data.scholarship_id', $scholarship->id)
        ->assertActionDataSet(['mode' => 'persen', 'value' => 50])
        ->fillForm([
            'student_id' => $f->a->id,
            'fee_type_id' => $f->spp->id,
            'reason' => 'Hafal 10 juz',
            'valid_from' => '2026-07-01',
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $override = FeeOverride::query()->sole();

    expect($override)->mode->toBe('persen')->value->toBe(50)->approved_by->toBe($user->id)
        ->scholarship_id->toBe($scholarship->id)->student_id->toBe($f->a->id);
});

it('AC-31: shows the overlapping override error', function () {
    $f = $this->f;
    $this->actingAs(keuanganUser('bendahara'));
    keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2026-07-01', 'valid_until' => null]);

    Livewire::test(ManageFeeOverrides::class)
        ->callAction('create', keringananFormData($f, ['valid_from' => '2026-09-01']))
        ->assertHasFormErrors(['valid_from']);

    expect(FeeOverride::query()->count())->toBe(1);
});

it('AC-31: edits an override', function () {
    $f = $this->f;
    $this->actingAs(keuanganUser('bendahara'));
    $override = keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id, 'mode' => 'nominal', 'value' => 250000, 'valid_from' => '2026-07-01', 'valid_until' => null]);

    Livewire::test(ManageFeeOverrides::class)
        ->callAction(TestAction::make('edit')->table($override), keringananFormData($f, ['value' => 200000]))
        ->assertHasNoFormErrors();

    expect($override->fresh()->value)->toBe(200000);
});

it('AC-31: has no delete action', function () {
    $f = $this->f;
    $this->actingAs(keuanganUser('bendahara'));
    $override = keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id]);

    Livewire::test(ManageFeeOverrides::class)
        ->assertTableActionDoesNotExist('delete', record: $override)
        ->assertActionDoesNotExist(TestAction::make('delete')->table($override))
        ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());
});

it('AC-32: formats override values', function (string $mode, int $value, string $expected) {
    $f = $this->f;
    $this->actingAs(keuanganUser('bendahara'));
    $override = keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id, 'mode' => $mode, 'value' => $value, 'valid_until' => null]);

    Livewire::test(ManageFeeOverrides::class)
        ->assertCanSeeTableRecords([$override])
        ->assertSeeText($expected)
        ->assertSeeText('Sampai lulus');
})->with([
    'free' => ['nominal', 0, 'Gratis'],
    'nominal' => ['nominal', 250000, 'Bayar Rp 250.000'],
    'discount' => ['potongan', 100000, 'Potongan Rp 100.000'],
    'percent' => ['persen', 50, 'Potongan 50%'],
]);

it('AC-32: filters overrides by validity', function (string $filter, string $expectedKey) {
    $this->travelTo('2026-10-15 09:00:00');
    $f = $this->f;
    $this->actingAs(keuanganUser('bendahara'));
    $records = [
        'aktif' => keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2026-07-01', 'valid_until' => null]),
        'akan_datang' => keringananOverride(['student_id' => $f->b->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2026-11-01', 'valid_until' => null]),
        'berakhir' => keringananOverride(['student_id' => $f->c->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2026-01-01', 'valid_until' => '2026-09-30']),
    ];
    $others = collect($records)->except($expectedKey)->values()->all();

    Livewire::test(ManageFeeOverrides::class)
        ->filterTable('validity', $filter)
        ->assertCanSeeTableRecords([$records[$expectedKey]])
        ->assertCanNotSeeTableRecords($others);
})->with([
    'active today' => ['aktif', 'aktif'],
    'upcoming' => ['akan_datang', 'akan_datang'],
    'ended' => ['berakhir', 'berakhir'],
]);

it('AC-33: ends an override from the table', function () {
    $f = $this->f;
    $this->actingAs(keuanganUser('bendahara'));
    $override = keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2026-07-01', 'valid_until' => null]);

    Livewire::test(ManageFeeOverrides::class)
        ->callAction(TestAction::make('end')->table($override), ['end_on' => '2026-10-31'])
        ->assertHasNoFormErrors();

    expect($override->fresh()->valid_until->toDateString())->toBe('2026-10-31');
});

it('AC-33: hides the end action for ended overrides', function () {
    $this->travelTo('2026-10-15 09:00:00');
    $f = $this->f;
    $this->actingAs(keuanganUser('bendahara'));
    $ended = keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2026-01-01', 'valid_until' => '2026-09-30']);
    $open = keringananOverride(['student_id' => $f->b->id, 'fee_type_id' => $f->spp->id, 'valid_until' => null]);
    $planned = keringananOverride(['student_id' => $f->c->id, 'fee_type_id' => $f->spp->id, 'valid_until' => '2026-12-31']);

    Livewire::test(ManageFeeOverrides::class)
        ->assertActionHidden(TestAction::make('end')->table($ended))
        ->assertActionVisible(TestAction::make('end')->table($open))
        ->assertActionVisible(TestAction::make('end')->table($planned));
});

it('AC-33: shows the end before start error', function () {
    $f = $this->f;
    $this->actingAs(keuanganUser('bendahara'));
    $override = keringananOverride(['student_id' => $f->a->id, 'fee_type_id' => $f->spp->id, 'valid_from' => '2026-07-01', 'valid_until' => null]);

    $component = Livewire::test(ManageFeeOverrides::class)
        ->callAction(TestAction::make('end')->table($override), ['end_on' => '2026-06-30']);

    $messages = collect($component->errors()->all())
        ->merge(asramaNotifications()->flatMap(fn (array $n): array => [(string) ($n['title'] ?? ''), (string) ($n['body'] ?? '')]));

    expect($messages->contains(fn (string $m): bool => preg_match(keuanganMessagePattern('end_before_start'), $m) === 1))
        ->toBeTrue('Pesan end_before_start tidak tampil: '.$messages->implode(' | '))
        ->and($override->fresh()->valid_until)->toBeNull();
});
