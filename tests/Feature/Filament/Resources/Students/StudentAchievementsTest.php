<?php

use App\Filament\Resources\Achievements\Pages\CreateAchievement;
use App\Filament\Resources\Students\Pages\EditStudent;
use App\Filament\Resources\Students\RelationManagers\AchievementsRelationManager;
use App\Models\Setting;
use App\Models\Student;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = prestasiFixture();
});

/**
 * Relation manager prestasi untuk santri.
 */
function prestasiStudentTab(Student $student): Testable
{
    return Livewire::test(AchievementsRelationManager::class, ['ownerRecord' => $student, 'pageClass' => EditStudent::class]);
}

it('AC-36: shows achievements on the student page', function () {
    $f = $this->f;
    $data = prestasiRecapData($f);
    $this->actingAs($f->a);

    expect(AchievementsRelationManager::canViewForRecord($f->s3, EditStudent::class))->toBeTrue();

    $component = prestasiStudentTab($f->s3)->assertCanSeeTableRecords([$data->p2, $data->pu]);
    $text = tagihanPlainText($component);

    expect($text)->toContain('Terverifikasi')->toContain('Belum diverifikasi')
        ->toContain('Juara 1')
        ->toContain('1 prestasi terverifikasi, tingkat tertinggi Pondok. 1 belum diverifikasi.');

    $s2 = tagihanPlainText(prestasiStudentTab($f->s2));

    expect($s2)->toContain('3 prestasi terverifikasi, tingkat tertinggi Nasional.')->not->toContain('belum diverifikasi');
});

it('AC-36: shows the rank and role of the student for each achievement', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [[$f->s2, 'Juara 2', 'Ketua tim'], [$f->s3, 'Harapan 1']], ['verified' => true, 'title' => 'Lomba tim']);
    $this->actingAs($f->a);

    $s2 = tagihanPlainText(prestasiStudentTab($f->s2)->assertCanSeeTableRecords([$achievement]));
    $s3 = tagihanPlainText(prestasiStudentTab($f->s3)->assertCanSeeTableRecords([$achievement]));

    expect($s2)->toContain('Juara 2')->toContain('Ketua tim')->not->toContain('Harapan 1')
        ->and($s3)->toContain('Harapan 1')->not->toContain('Ketua tim');
});

it('AC-36: shows the empty description for a student without verified achievements', function () {
    $f = $this->f;
    $this->actingAs($f->a);

    expect(tagihanPlainText(prestasiStudentTab($f->s2)))->toContain('Belum ada prestasi terverifikasi.');

    prestasiCreate($f, [$f->s2], ['title' => 'Belum diverifikasi']);

    $text = tagihanPlainText(prestasiStudentTab($f->s2));

    expect($text)->toContain('Belum ada prestasi terverifikasi.')->toContain('1 belum diverifikasi');
});

it('AC-36: offers no edit or delete and only lets creators add achievements', function () {
    $f = $this->f;
    $data = prestasiRecapData($f);
    $this->actingAs($f->a);

    $component = prestasiStudentTab($f->s3);
    $actions = array_keys($component->instance()->getTable()->getFlatActions());

    expect($actions)->not->toContain('edit')->not->toContain('delete')
        ->and($component->instance()->getTable()->getFlatBulkActions())->toBe([])
        ->and(tagihanPlainText($component))->not->toContain(__('pondok.achievement.create_action'));

    $this->actingAs($f->admin);

    $html = prestasiStudentTab($f->s3)->html();

    expect(strip_tags($html))->toContain(__('pondok.achievement.create_action'))
        ->and(html_entity_decode($html))->toContain(CreateAchievement::getUrl(['student' => $f->s3->id]))
        ->and($data->p2)->not->toBeNull();
});

it('AC-36: hides the tab when the prestasi module is off but keeps the student page open', function () {
    $f = $this->f;
    Setting::set('modules.prestasi.enabled', false);
    $this->actingAs($f->a);

    expect(AchievementsRelationManager::canViewForRecord($f->s3, EditStudent::class))->toBeFalse();

    $this->get("/admin/students/{$f->s3->id}/edit")->assertOk();
});

it('AC-36: hides the tab from users without access to achievements', function () {
    $f = $this->f;
    $this->actingAs(keuanganUser('bendahara'));

    expect(AchievementsRelationManager::canViewForRecord($f->s3, EditStudent::class))->toBeFalse();
});

it('AC-36: shows the tab on the student edit page', function () {
    $f = $this->f;
    $this->actingAs($f->a);

    $component = Livewire::test(EditStudent::class, ['record' => $f->s3->getRouteKey()]);
    $key = array_search(AchievementsRelationManager::class, $component->instance()->getRelationManagers(), true);

    expect($key)->not->toBeFalse()
        ->and(AchievementsRelationManager::canViewForRecord($f->s3, EditStudent::class))->toBeTrue();
});
