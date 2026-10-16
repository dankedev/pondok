<?php

use App\Filament\Resources\Achievements\Pages\CreateAchievement;
use App\Filament\Resources\Achievements\Pages\EditAchievement;
use App\Models\Achievement;
use Filament\Forms\Components\Select;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = prestasiFixture();
});

/**
 * Isian form prestasi yang valid (individu, peserta `s2`).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function prestasiFormData(object $f, array $overrides = []): array
{
    return [
        'title' => 'Juara 1 MTQ Tahfidz 10 Juz',
        'event_name' => 'MTQ Tingkat Kabupaten 2026',
        'organizer' => 'Kemenag Kabupaten',
        'category' => 'tahfidz',
        'level' => 'kabupaten',
        'kind' => 'individu',
        'achieved_on' => '2026-08-17',
        'is_public' => false,
        'participants' => [['student_id' => $f->s2->id, 'rank' => 'Juara 1']],
        ...$overrides,
    ];
}

/**
 * Select santri pada item peserta pertama (butuh minimal satu peserta di state form).
 */
function prestasiParticipantSelect(Testable $component): Select
{
    $select = collect($component->instance()->form->getFlatComponents(withHidden: true))
        ->first(fn ($child, $key): bool => $child instanceof Select && str_ends_with((string) $key, 'student_id'));

    expect($select)->toBeInstanceOf(Select::class, 'Select student_id pada Repeater peserta tidak ditemukan');

    return $select;
}

/**
 * Id santri yang ditawarkan Select (opsi statis ditambah hasil pencarian `$search`).
 *
 * @return list<int>
 */
function prestasiSelectIds(Select $select, string $search): array
{
    $options = $select->getOptions();

    try {
        $options += $select->getSearchResults($search);
    } catch (Throwable) {
    }

    return array_map('intval', array_keys($options));
}

it('AC-30: creates an achievement through the form', function () {
    $f = $this->f;
    $this->actingAs($f->k);

    Livewire::test(CreateAchievement::class)
        ->fillForm(prestasiFormData($f, [
            'kind' => 'beregu',
            'is_public' => true,
            'participants' => [
                ['student_id' => $f->s2->id, 'rank' => 'Juara 1', 'role' => 'Ketua tim'],
                ['student_id' => $f->s3->id, 'rank' => 'Juara 1'],
            ],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $achievement = Achievement::withoutUnitScope()->sole();

    expect($achievement->recorded_by)->toBe($f->k->id)
        ->and($achievement->verified_by)->toBeNull()
        ->and($achievement->title)->toBe('Juara 1 MTQ Tahfidz 10 Juz')
        ->and($achievement->organizer)->toBe('Kemenag Kabupaten')
        ->and($achievement->kind)->toBe('beregu')
        ->and($achievement->is_public)->toBeTrue()
        ->and($achievement->academic_year_id)->toBe($f->y2->id)
        ->and(prestasiPivot($achievement))->toBe([$f->s2->id => ['Juara 1', 'Ketua tim'], $f->s3->id => ['Juara 1', null]]);
});

it('AC-30: shows participant errors on the form', function () {
    $f = $this->f;
    $this->actingAs($f->k);

    $component = Livewire::test(CreateAchievement::class)
        ->fillForm(prestasiFormData($f, ['kind' => 'beregu']))
        ->call('create')
        ->assertHasFormErrors(['participants']);

    expect(prestasiSeeMessage($component, 'team_too_small'))->toBeTrue()
        ->and(Achievement::withoutUnitScope()->count())->toBe(0);
});

it('AC-30: shows date errors on the field', function () {
    $f = $this->f;
    $this->actingAs($f->k);

    $component = Livewire::test(CreateAchievement::class)
        ->fillForm(prestasiFormData($f, ['achieved_on' => '2024-01-10']))
        ->call('create')
        ->assertHasFormErrors(['achieved_on']);

    expect(prestasiSeeMessage($component, 'academic_year_not_found'))->toBeTrue()
        ->and(Achievement::withoutUnitScope()->count())->toBe(0);
});

it('AC-30: requires the main fields', function () {
    $this->actingAs($this->f->k);

    Livewire::test(CreateAchievement::class)
        ->fillForm(['title' => null, 'event_name' => null, 'category' => null, 'level' => null, 'kind' => null, 'achieved_on' => null])
        ->call('create')
        ->assertHasFormErrors(['title', 'event_name', 'category', 'level', 'kind', 'achieved_on']);

    expect(Achievement::withoutUnitScope()->count())->toBe(0);
});

it('AC-30: limits the student options to the unit scope', function () {
    $f = $this->f;
    $this->actingAs($f->k2);

    $component = Livewire::withQueryParams(['student' => $f->s2->id])->test(CreateAchievement::class);
    $select = prestasiParticipantSelect($component);

    expect(prestasiSelectIds($select, 'Budi'))->toContain($f->s2->id)
        ->and(prestasiSelectIds($select, 'Ahmad'))->not->toContain($f->s1->id)
        ->and(prestasiSelectIds($select, '1001'))->not->toContain($f->s1->id);
});

it('AC-30: leaves archived students out of the options', function () {
    $f = $this->f;
    $archived = prestasiStudent($f, 'SLTP', ['name' => 'Budi Arsip']);
    $archived->delete();
    $this->actingAs($f->k);

    $select = prestasiParticipantSelect(Livewire::withQueryParams(['student' => $f->s2->id])->test(CreateAchievement::class));

    expect(prestasiSelectIds($select, 'Budi'))->toContain($f->s2->id)->not->toContain($archived->id);
});

it('AC-30: prefills the first participant from the student query parameter', function () {
    $f = $this->f;
    $this->actingAs($f->k);

    $component = Livewire::withQueryParams(['student' => $f->s2->id])->test(CreateAchievement::class);
    $participants = array_values($component->get('data.participants') ?? []);

    expect($participants)->toHaveCount(1)
        ->and((int) $participants[0]['student_id'])->toBe($f->s2->id);
});

it('AC-31: edits a verified achievement', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [[$f->s2, 'Juara 1', 'Ketua tim'], [$f->s3, 'Harapan 2']], [
        'verified' => true,
        'title' => 'Juara 1 MTQ Tahfidz 10 Juz',
        'organizer' => 'Kemenag Kabupaten',
    ]);
    $this->actingAs($f->k);

    $component = Livewire::test(EditAchievement::class, ['record' => $achievement->getRouteKey()]);
    $participants = collect($component->get('data.participants'))->mapWithKeys(fn (array $item): array => [(int) $item['student_id'] => [$item['rank'], $item['role'] ?? null]])->all();

    expect($participants)->toBe([$f->s2->id => ['Juara 1', 'Ketua tim'], $f->s3->id => ['Harapan 2', null]])
        ->and(tagihanPlainText($component))->toContain(__('pondok.achievement.edit_resets_verification'));

    $component->fillForm(['title' => 'Juara 1 MTQ Tahfidz 15 Juz'])->call('save')->assertHasNoFormErrors()->assertNotified();

    expect($achievement->fresh())->verified_by->toBeNull()->title->toBe('Juara 1 MTQ Tahfidz 15 Juz')->recorded_by->toBe($f->k->id);
});

it('AC-31: does not show the reset warning for an unverified achievement', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [$f->s2]);
    $this->actingAs($f->k);

    $component = Livewire::test(EditAchievement::class, ['record' => $achievement->getRouteKey()]);

    expect(tagihanPlainText($component))->not->toContain(__('pondok.achievement.edit_resets_verification'));
});

it('AC-31: shows save errors on the form when editing', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [$f->s2]);
    $this->actingAs($f->k);

    $component = Livewire::test(EditAchievement::class, ['record' => $achievement->getRouteKey()])
        ->fillForm(['kind' => 'beregu'])
        ->call('save')
        ->assertHasFormErrors(['participants']);

    expect(prestasiSeeMessage($component, 'team_too_small'))->toBeTrue()
        ->and($achievement->fresh()->kind)->toBe('individu');
});

it('AC-31: keeps an archived participant when editing', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [$f->s2, $f->s3], ['title' => 'Tim']);
    $f->s3->delete();
    $this->actingAs($f->k);

    Livewire::test(EditAchievement::class, ['record' => $achievement->getRouteKey()])
        ->fillForm(['title' => 'Tim diubah'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(array_keys(prestasiPivot($achievement)))->toBe([$f->s2->id, $f->s3->id])
        ->and($achievement->fresh()->title)->toBe('Tim diubah');
});
