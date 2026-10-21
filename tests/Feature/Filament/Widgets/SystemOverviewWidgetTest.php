<?php

use App\Models\AcademicYear;
use App\Models\User;

beforeEach(function () {
    $this->f = dashboardBoot();
});

it('AC-14: renders system status', function () {
    dashboardEnableThreeModules();
    User::factory()->count(3)->create();
    User::factory()->inactive()->create();

    $text = dashboardWidgetText(keuanganUser('admin-sekolah'), 'system');

    expect($text)->toContain('Status sistem')
        ->and($text)->toContain('2026/2027')
        ->and($text)->toContain('3 dari 7')
        ->and($text)->not->toContain('Modul belum tersedia.');
});

it('AC-14: handles a missing active academic year', function () {
    AcademicYear::query()->update(['is_active' => false]);

    $text = dashboardWidgetText(keuanganUser('admin-sekolah'), 'system');

    expect($text)->toContain('Belum ada tahun ajaran aktif.');
});

it('AC-14: links to academic years only when allowed', function () {
    $url = route('filament.admin.resources.academic-years.index');
    $link = __('pondok.dashboard.system.academic_year_link');

    expect($link)->toBe('Atur tahun ajaran');

    $withLink = dashboardGet(keuanganUser('super-admin'));
    expect(bayarHtmlText($withLink->getContent()))->toContain($link);
    $withLink->assertSee($url, false);

    $withoutLink = dashboardGet(dashboardUserWith(['view-dashboard', 'view-settings-metrics']));
    $withoutLink->assertSeeLivewire(dashboardWidgets()['system']);
    expect(bayarHtmlText($withoutLink->getContent()))->toContain('Status sistem')
        ->and(bayarHtmlText($withoutLink->getContent()))->not->toContain($link);
    $withoutLink->assertDontSee($url, false);
});
