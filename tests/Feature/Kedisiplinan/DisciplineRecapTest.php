<?php

use App\Actions\Kedisiplinan\DeleteDisciplineLevel;
use App\Actions\Kedisiplinan\SaveDisciplineLevel;
use App\Actions\Kedisiplinan\SummarizeDisciplinePoints;
use App\Filament\Pages\DisciplineRecap;
use App\Filament\Resources\DisciplineLevels\Pages\ListDisciplineLevels;
use App\Models\AcademicYear;
use App\Models\DisciplineLevel;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = disiplinFixture();
});

/**
 * Data rekap: s2 50 poin, s1 30 poin (+ catatan batal dan tahun ajaran lain yang tidak dihitung), s4 (SLTA) tepat 25 poin,
 * s3 10 poin, s5 hanya catatan batal. Ambang 25 "Peringatan 1" dan 50 "Peringatan 2".
 */
function disiplinRecapData(object $f): object
{
    disiplinLevel('Peringatan 1', 25);
    disiplinLevel('Peringatan 2', 50);

    $f->s5 = tahfidzStudent($f, 'L', null, ['name' => 'Santri Lima']);
    $ten = disiplinType('Sepuluh poin', 'ringan', 10);

    disiplinRaw($f, $f->s2, $f->berat);
    disiplinRaw($f, $f->s1, $f->sedang);
    disiplinRaw($f, $f->s1, $f->ringan);
    disiplinRawCancelled($f, $f->s1, $f->berat);
    disiplinRaw($f, $f->s1, $f->berat, ['academic_year_id' => $f->prevYear->id, 'occurred_on' => '2026-03-01']);
    disiplinRaw($f, $f->s4, $f->sedang);
    disiplinRaw($f, $f->s3, $ten);
    disiplinRawCancelled($f, $f->s5, $f->berat);

    return $f;
}

/**
 * Baris rekap per student_id: [jumlah pelanggaran, total poin, label level].
 *
 * @return array<int, array{0: int, 1: int, 2: ?string}>
 */
function disiplinSummary(User $actor, int $yearId, ?int $unitId = null): array
{
    test()->actingAs($actor);

    return collect(app(SummarizeDisciplinePoints::class)->handle($actor, $yearId, $unitId))
        ->mapWithKeys(fn (mixed $row): array => [(int) data_get($row, 'student_id') => [(int) data_get($row, 'violations_count'), (int) data_get($row, 'total_points'), data_get($row, 'level')]])
        ->all();
}

it('AC-10: totals the points per student for the chosen year, in descending order, with the level label', function () {
    $f = disiplinRecapData($this->f);

    $rows = disiplinSummary($f->kes, $f->year->id);

    expect(array_keys($rows))->toBe([$f->s2->id, $f->s1->id, $f->s4->id, $f->s3->id])
        ->and($rows[$f->s2->id])->toBe([1, 50, 'Peringatan 2'])
        ->and($rows[$f->s1->id])->toBe([2, 30, 'Peringatan 1'])
        ->and($rows[$f->s4->id])->toBe([1, 25, 'Peringatan 1'])
        ->and($rows[$f->s3->id])->toBe([1, 10, null])
        ->and($rows)->not->toHaveKey($f->s5->id);

    $previous = disiplinSummary($f->kes, $f->prevYear->id);

    expect(array_keys($previous))->toBe([$f->s1->id])->and($previous[$f->s1->id])->toBe([1, 50, 'Peringatan 2']);
});

it('AC-10: filters by unit, by the unit scope of the user and by the supervised students of a musyrif', function () {
    $f = disiplinRecapData($this->f);

    expect(array_keys(disiplinSummary($f->kes, $f->year->id, $f->slta->id)))->toBe([$f->s4->id])
        ->and(array_keys(disiplinSummary($f->kesSltp, $f->year->id)))->toBe([$f->s2->id, $f->s1->id, $f->s3->id])
        ->and(array_keys(disiplinSummary($f->musyrif, $f->year->id)))->toBe([$f->s1->id]);
});

it('AC-10: leaves a student without a matching threshold unlabeled when there are no thresholds', function () {
    $f = $this->f;
    disiplinRaw($f, $f->s1, $f->berat);

    expect(disiplinSummary($f->kes, $f->year->id)[$f->s1->id])->toBe([1, 50, null]);
});

it('AC-10: saves a threshold and rejects a zero or duplicate minimum and an empty name', function () {
    $f = $this->f;
    $this->actingAs($f->kes);

    $level = app(SaveDisciplineLevel::class)->handle($f->kes, ['name' => 'Peringatan 1', 'min_points' => 25]);

    expect($level->min_points)->toBe(25);
    expect(app(SaveDisciplineLevel::class)->handle($f->kes, ['name' => 'Peringatan Satu', 'min_points' => 25], $level)->name)->toBe('Peringatan Satu');

    foreach ([
        'min 0' => [['name' => 'Nol', 'min_points' => 0], 'min_points'],
        'min ganda' => [['name' => 'Ganda', 'min_points' => 25], 'min_points'],
        'nama kosong' => [['name' => '', 'min_points' => 40], 'name'],
    ] as $label => [$data, $field]) {
        try {
            app(SaveDisciplineLevel::class)->handle($f->kes, $data);
            $this->fail("{$label}: ValidationException diharapkan");
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($field, message: $label);
        }
    }

    expect(DisciplineLevel::query()->count())->toBe(1);
});

it('AC-10: deletes a threshold through the Action for kesantrian only', function () {
    $f = $this->f;
    $level = disiplinLevel('Peringatan 1', 25);
    $keep = disiplinLevel('Peringatan 2', 50);

    foreach ([$f->musyrif, $f->adminSekolah, $f->waliKelas] as $user) {
        $this->actingAs($user);

        expect(fn () => app(DeleteDisciplineLevel::class)->handle($user, $level))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SaveDisciplineLevel::class)->handle($user, ['name' => 'X', 'min_points' => 5]))->toThrow(AuthorizationException::class);
    }

    expect(DisciplineLevel::query()->count())->toBe(2);

    $this->actingAs($f->kes);
    app(DeleteDisciplineLevel::class)->handle($f->kes, $level);

    expect(DisciplineLevel::query()->pluck('id')->all())->toBe([$keep->id]);

    Livewire::test(ListDisciplineLevels::class)->assertCanSeeTableRecords([$keep])->assertTableActionExists('delete');
});

it('AC-10: renders the recap page with the active year by default, ordered, with labels, and switches the year', function () {
    $f = disiplinRecapData($this->f);
    $this->actingAs($f->kes);

    $component = Livewire::test(DisciplineRecap::class);

    $component->assertSeeInOrder(['Santri Dua', 'Santri Satu', 'Santri Empat', 'Santri Tiga'])
        ->assertSee('Peringatan 2')->assertSee('Peringatan 1')
        ->assertDontSee('Santri Lima');

    $component->set('data.academic_year_id', $f->prevYear->id)
        ->assertSee('Santri Satu')->assertDontSee('Santri Dua')->assertDontSee('Santri Tiga');

    $component->set('data.academic_year_id', $f->year->id)->set('data.unit_id', $f->slta->id)
        ->assertSee('Santri Empat')->assertDontSee('Santri Satu')->assertDontSee('Santri Dua');
});

it('AC-10: shows a musyrif only the supervised students on the recap page', function () {
    $f = disiplinRecapData($this->f);
    $this->actingAs($f->musyrif);

    Livewire::test(DisciplineRecap::class)
        ->assertSee('Santri Satu')
        ->assertDontSee('Santri Dua')->assertDontSee('Santri Tiga')->assertDontSee('Santri Empat');
});

it('AC-10: renders the recap with an empty state and no error when there is no active academic year', function () {
    $f = disiplinRecapData($this->f);
    AcademicYear::query()->update(['is_active' => false]);
    $this->actingAs($f->kes);

    $this->get('/admin/discipline-recap')->assertOk()->assertDontSee('Santri Dua');
    Livewire::test(DisciplineRecap::class)->assertOk()->assertDontSee('Santri Dua');
});
