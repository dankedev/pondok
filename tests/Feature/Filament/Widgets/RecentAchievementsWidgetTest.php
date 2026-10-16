<?php

use App\Filament\Widgets\RecentAchievementsWidget;
use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = prestasiFixture();
});

it('AC-38: lists recent verified achievements on the dashboard', function () {
    $f = $this->f;

    foreach (range(1, 6) as $day) {
        prestasiCreate($f, [$f->s2], ['verified' => true, 'title' => "Prestasi V{$day}", 'achieved_on' => "2026-08-0{$day}"]);
    }

    prestasiCreate($f, [$f->s3], ['title' => 'Prestasi Belum', 'achieved_on' => '2026-09-30']);
    $this->actingAs($f->a);

    $text = dashboardWidgetText($f->a, 'achievements');

    expect(RecentAchievementsWidget::isModuleAvailable())->toBeTrue()
        ->and($text)->toContain('Prestasi V6')->toContain('Prestasi V5')->toContain('Prestasi V4')->toContain('Prestasi V3')->toContain('Prestasi V2')
        ->not->toContain('Prestasi V1')->not->toContain('Prestasi Belum')
        ->and(strpos($text, 'Prestasi V6'))->toBeLessThan(strpos($text, 'Prestasi V5'))
        ->and(strpos($text, 'Prestasi V3'))->toBeLessThan(strpos($text, 'Prestasi V2'))
        ->and($text)->toContain('6 Agu 2026')->toContain('Budi Dua')->toContain(__('pondok.achievement.levels.kabupaten'))
        ->toContain(__('pondok.dashboard.achievements.list_link'))
        ->not->toContain('Modul belum tersedia.');
});

it('AC-38: links to the achievement list', function () {
    $f = $this->f;
    prestasiCreate($f, [$f->s2], ['verified' => true]);
    $this->actingAs($f->a);

    $html = Livewire::test(RecentAchievementsWidget::class)->html();

    expect($html)->toContain(url('/admin/achievements'));
});

it('AC-38: shows the empty state when nothing is verified', function () {
    $f = $this->f;
    prestasiCreate($f, [$f->s2], ['title' => 'Prestasi Belum']);

    $text = dashboardWidgetText($f->a, 'achievements');

    expect($text)->toContain('Belum ada prestasi terverifikasi.')->not->toContain('Prestasi Belum')->not->toContain('Modul belum tersedia.');
});

it('AC-38: shows the unavailable state when the module is off or the table is missing', function () {
    $f = $this->f;
    prestasiCreate($f, [$f->s2], ['verified' => true, 'title' => 'Prestasi V1']);

    Setting::set('modules.prestasi.enabled', false);

    $text = dashboardWidgetText($f->a, 'achievements');

    expect(RecentAchievementsWidget::isModuleAvailable())->toBeFalse()
        ->and($text)->toContain('Modul belum tersedia.')->not->toContain('Prestasi V1');

    Setting::set('modules.prestasi.enabled', true);
    Schema::disableForeignKeyConstraints();
    Schema::drop('achievement_student');
    Schema::enableForeignKeyConstraints();

    $text = dashboardWidgetText($f->a, 'achievements');

    expect(RecentAchievementsWidget::isModuleAvailable())->toBeFalse()
        ->and($text)->toContain('Modul belum tersedia.');
});

it('AC-38: hides achievements of other units from a scoped user', function () {
    $f = $this->f;
    prestasiCreate($f, [$f->s1], ['verified' => true, 'title' => 'Prestasi SD']);
    prestasiCreate($f, [$f->s2], ['verified' => true, 'title' => 'Prestasi SLTP']);

    $text = dashboardWidgetText($f->a2, 'achievements');

    expect($text)->toContain('Prestasi SLTP')->not->toContain('Prestasi SD');
});

it('AC-38: requires achievement access to view the widget', function () {
    $f = $this->f;
    $metricsOnly = dashboardUserWith(['view-dashboard', 'view-student-metrics']);
    $withAccess = dashboardUserWith(['view-dashboard', 'view-student-metrics', 'view-any:achievement']);

    $this->actingAs($metricsOnly);
    expect(RecentAchievementsWidget::canView())->toBeFalse();
    dashboardGet($metricsOnly)->assertDontSeeLivewire(RecentAchievementsWidget::class);

    $this->actingAs($withAccess);
    expect(RecentAchievementsWidget::canView())->toBeTrue();
    dashboardGet($withAccess)->assertSeeLivewire(RecentAchievementsWidget::class);

    foreach (['admin-sekolah', 'kesantrian', 'super-admin'] as $role) {
        $this->actingAs(keuanganUser($role));

        expect(RecentAchievementsWidget::canView())->toBeTrue();
    }

    foreach (['bendahara', 'admin-konten', 'admin-psb', 'musyrif'] as $role) {
        $this->actingAs(keuanganUser($role));

        expect(RecentAchievementsWidget::canView())->toBeFalse();
    }
});
