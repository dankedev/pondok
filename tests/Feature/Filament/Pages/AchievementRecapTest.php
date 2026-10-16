<?php

use App\Filament\Pages\AchievementRecap;
use App\Models\AcademicYear;
use App\Models\Achievement;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = prestasiFixture();
});

/**
 * Angka pada baris tabel yang memuat `$label` (semua sel selain sel pertama), atau null bila baris tidak ada.
 *
 * @return list<int>|null
 */
function prestasiMatrixRow(Testable $component, string $label): ?array
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$component->html());

    foreach ((new DOMXPath($dom))->query('//tr') as $row) {
        $cells = [];

        foreach ((new DOMXPath($dom))->query('./th|./td', $row) as $cell) {
            $cells[] = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $cell->textContent));
        }

        if (($cells[0] ?? null) === $label) {
            return array_map('intval', array_slice($cells, 1));
        }
    }

    return null;
}

/**
 * Teks baris tabel pertama yang memuat `$needle`.
 */
function prestasiRowText(Testable $component, string $needle): ?string
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$component->html());

    foreach ((new DOMXPath($dom))->query('//tr') as $row) {
        $text = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $row->textContent));

        if (str_contains($text, $needle)) {
            return $text;
        }
    }

    return null;
}

/**
 * Apakah toggle "Termasuk yang belum diverifikasi" tampil di form filter.
 */
function prestasiToggleVisible(Testable $component): bool
{
    $components = $component->instance()->form->getFlatComponents(withHidden: false);

    return isset($components['include_unverified']) || isset($components['data.include_unverified']);
}

it('AC-35: shows the verified achievement recap', function () {
    $f = $this->f;
    prestasiRecapData($f);
    $this->actingAs($f->a);

    $component = Livewire::test(AchievementRecap::class);
    $text = tagihanPlainText($component);

    expect($text)->toContain('Hanya prestasi terverifikasi.')
        ->and(prestasiMatrixRow($component, __('pondok.achievement.categories.tahfidz')))->toBe([1, 0, 1, 0, 0, 0, 2])
        ->and(prestasiMatrixRow($component, __('pondok.achievement.categories.olahraga')))->toBe([0, 0, 0, 1, 0, 0, 1])
        ->and(prestasiMatrixRow($component, __('pondok.achievement.categories.akademik')))->toBe([0, 0, 0, 0, 0, 0, 0])
        ->and(prestasiMatrixRow($component, __('pondok.achievement.categories.seni')))->toBe([0, 0, 0, 0, 0, 0, 0]);
});

it('AC-35: lists students with achievements by count then name', function () {
    $f = $this->f;
    prestasiRecapData($f);
    $this->actingAs($f->a);

    $component = Livewire::test(AchievementRecap::class)
        ->assertCanSeeTableRecords([$f->s2, $f->s1, $f->s3], inOrder: true);

    expect(prestasiRowText($component, 'Budi Dua'))->toContain('Kabupaten')->toContain('2')
        ->and(prestasiRowText($component, 'Candra Tiga'))->toContain('Pondok')->not->toContain('Nasional')
        ->and(prestasiRowText($component, 'Ahmad Satu'))->toContain('Provinsi')
        ->and(tagihanPlainText($component))->toContain(__('pondok.achievement.view_achievements'));
});

it('AC-35: includes unverified achievements for managers only', function () {
    $f = $this->f;
    prestasiRecapData($f);
    $this->actingAs($f->a);

    $component = Livewire::test(AchievementRecap::class);

    expect(prestasiToggleVisible($component))->toBeTrue();

    $component->set('data.include_unverified', true);

    expect(tagihanPlainText($component))->toContain('Termasuk prestasi yang belum diverifikasi.')
        ->and(prestasiMatrixRow($component, __('pondok.achievement.categories.akademik')))->toBe([0, 0, 0, 0, 1, 0, 1])
        ->and(prestasiRowText($component, 'Candra Tiga'))->toContain('Nasional');
});

it('AC-35: ignores the unverified toggle for users who cannot manage achievements', function () {
    $f = $this->f;
    prestasiRecapData($f);
    $viewer = dashboardUserWith(['view-any:achievement', 'view:achievement']);
    $this->actingAs($viewer);

    $component = Livewire::test(AchievementRecap::class);

    expect(prestasiToggleVisible($component))->toBeFalse();

    $component->set('data.include_unverified', true);

    expect(prestasiMatrixRow($component, __('pondok.achievement.categories.akademik')))->toBe([0, 0, 0, 0, 0, 0, 0])
        ->and(tagihanPlainText($component))->toContain('Hanya prestasi terverifikasi.')
        ->and(prestasiRowText($component, 'Candra Tiga'))->not->toContain('Nasional');
});

it('AC-35: shows the toggle to kesantrian who can create achievements', function () {
    $this->actingAs($this->f->k);

    expect(prestasiToggleVisible(Livewire::test(AchievementRecap::class)))->toBeTrue();
});

it('AC-35: filters by unit and shows an empty state for a year without data', function () {
    $f = $this->f;
    prestasiRecapData($f);
    $this->actingAs($f->a);

    $component = Livewire::test(AchievementRecap::class)->set('data.unit_id', $f->sltp->id);

    expect(tagihanPlainText($component))->not->toContain('Ahmad Satu')->toContain('Budi Dua');

    $empty = AcademicYear::factory()->create(['name' => '2024/2025', 'starts_on' => '2024-07-01', 'ends_on' => '2025-06-30']);
    $component = Livewire::test(AchievementRecap::class)->set('data.academic_year_id', $empty->id);

    expect(tagihanPlainText($component))->toContain('Belum ada prestasi untuk tahun ajaran ini.');
});

it('AC-35: hides students of other units from a scoped user', function () {
    $f = $this->f;
    prestasiRecapData($f);
    prestasiCreate($f, [$f->s1, $f->s2], ['verified' => true]);
    $this->actingAs($f->a2);

    $component = Livewire::test(AchievementRecap::class);

    expect(tagihanPlainText($component))->not->toContain('Ahmad Satu')->toContain('Budi Dua')
        ->and(Achievement::query()->count())->toBeGreaterThan(0);
});

it('AC-35: defaults to the active academic year', function () {
    $f = $this->f;
    $this->actingAs($f->a);

    Livewire::test(AchievementRecap::class)->assertSet('data.academic_year_id', $f->y2->id);
});
