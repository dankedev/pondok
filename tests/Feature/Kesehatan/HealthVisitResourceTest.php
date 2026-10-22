<?php

use App\Filament\Resources\HealthVisits\Pages\CreateHealthVisit;
use App\Filament\Resources\HealthVisits\Pages\EditHealthVisit;
use App\Filament\Resources\HealthVisits\Pages\ListHealthVisits;
use App\Filament\Resources\Students\Pages\EditStudent;
use App\Filament\Resources\Students\RelationManagers\HealthVisitsRelationManager;
use App\Models\HealthVisit;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = kesehatanFixture();
});

it('AC-11: lists newest first with the Indonesian date format and flag badges only when true', function () {
    $f = $this->f;
    $old = kesehatanRaw($f->s1, ['visited_at' => '2026-10-10 09:00']);
    $new = kesehatanRaw($f->s2, ['visited_at' => '2026-10-15 14:30', 'needs_referral' => true, 'needs_leave' => true]);
    $mid = kesehatanRaw($f->s3, ['visited_at' => '2026-10-12 08:00', 'complaint' => str_repeat('panjang ', 30)]);
    $this->actingAs($f->kes);

    $component = Livewire::test(ListHealthVisits::class)->assertCanSeeTableRecords([$new, $mid, $old], inOrder: true);
    $text = tagihanPlainText($component);

    expect($text)->toContain('15 Okt 2026, 14.30')
        ->toContain('Santri Dua')->toContain($f->s2->nis)
        ->toContain(__('pondok.health_visit.badges.needs_referral'))
        ->toContain(__('pondok.health_visit.badges.needs_leave'))
        ->and(__('pondok.health_visit.badges.needs_referral'))->toBe('Perlu rujukan')
        ->and(__('pondok.health_visit.badges.needs_leave'))->toBe('Perlu izin pulang')
        ->and($text)->not->toContain(str_repeat('panjang ', 30));

    HealthVisit::query()->whereKey($new->id)->update(['needs_referral' => false, 'needs_leave' => false]);
    $plain = tagihanPlainText(Livewire::test(ListHealthVisits::class));

    expect($plain)->not->toContain('Perlu rujukan')->not->toContain('Perlu izin pulang');
});

it('AC-11: filters by date range, unit and referral, and searches by student name', function () {
    $f = $this->f;
    $a = kesehatanRaw($f->s1, ['visited_at' => '2026-10-05 09:00']);
    $b = kesehatanRaw($f->s2, ['visited_at' => '2026-10-12 09:00', 'needs_referral' => true]);
    $c = kesehatanRaw($f->s4, ['visited_at' => '2026-10-14 09:00']);
    $this->actingAs($f->kes);

    Livewire::test(ListHealthVisits::class)
        ->filterTable('visited_at', ['from' => '2026-10-10', 'until' => '2026-10-13'])
        ->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c])
        ->resetTableFilters()
        ->filterTable('unit', $f->slta->id)
        ->assertCanSeeTableRecords([$c])->assertCanNotSeeTableRecords([$a, $b])
        ->resetTableFilters()
        ->filterTable('needs_referral', true)
        ->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c])
        ->resetTableFilters()
        ->filterTable('needs_referral', false)
        ->assertCanSeeTableRecords([$a, $c])->assertCanNotSeeTableRecords([$b])
        ->resetTableFilters()
        ->searchTable('Santri Empat')
        ->assertCanSeeTableRecords([$c])->assertCanNotSeeTableRecords([$a, $b]);
});

it('AC-11: shows an Indonesian empty state', function () {
    $this->actingAs($this->f->kes);

    expectTranslatedKeys(['pondok.health_visit.empty.heading', 'pondok.health_visit.empty.description']);

    $text = tagihanPlainText(Livewire::test(ListHealthVisits::class)->assertCountTableRecords(0));

    expect($text)->toContain(__('pondok.health_visit.empty.heading'));
});

it('AC-12: creates a visit through the form via the Action', function () {
    $f = $this->f;
    $this->actingAs($f->kes);

    Livewire::test(CreateHealthVisit::class)
        ->fillForm([
            'student_id' => $f->s1->id,
            'visited_at' => '2026-10-15 09:00',
            'complaint' => 'Luka lecet di lutut',
            'treatment' => 'Dibersihkan dan diplester',
            'needs_referral' => false,
            'needs_leave' => true,
            'note' => null,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $visit = HealthVisit::query()->sole();

    expect($visit->recorded_by)->toBe($f->kes->id)
        ->and($visit->student_id)->toBe($f->s1->id)
        ->and($visit->needs_leave)->toBeTrue()
        ->and(kesehatanActivities('created'))->toHaveCount(1);
});

it('AC-12: creates nothing when the Action rejects the form input', function () {
    $f = $this->f;
    $this->actingAs($f->kes);

    Livewire::test(CreateHealthVisit::class)
        ->fillForm(['student_id' => $f->s1->id, 'visited_at' => '2026-10-20 09:00', 'complaint' => 'Pusing'])
        ->call('create');

    expect(HealthVisit::query()->count())->toBe(0);
});

it('AC-12: disables the student field when editing and saves the other changes', function () {
    $f = $this->f;
    $visit = kesehatanRaw($f->s1);
    $this->actingAs($f->kes);

    Livewire::test(EditHealthVisit::class, ['record' => $visit->getKey()])
        ->assertFormFieldIsDisabled('student_id')
        ->fillForm(['complaint' => 'Batuk berdahak'])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $visit->fresh();

    expect($fresh->complaint)->toBe('Batuk berdahak')->and($fresh->student_id)->toBe($f->s1->id);
});

it('AC-13: shows a read-only tab with only the visits of that student', function () {
    $f = $this->f;
    $own = kesehatanRaw($f->s1);
    $other = kesehatanRaw($f->s2);
    $this->actingAs($f->kes);

    expect(HealthVisitsRelationManager::canViewForRecord($f->s1, EditStudent::class))->toBeTrue();

    $component = Livewire::test(HealthVisitsRelationManager::class, ['ownerRecord' => $f->s1, 'pageClass' => EditStudent::class])
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$other]);

    expect($component->instance()->isReadOnly())->toBeTrue();
});

it('AC-13: hides the tab from roles without view-any:health-visit (:role)', function (string $role) {
    $this->actingAs(asramaUser($role));

    expect(HealthVisitsRelationManager::canViewForRecord($this->f->s1, EditStudent::class))->toBeFalse();
})->with(['admin-sekolah', 'bendahara']);
