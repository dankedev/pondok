<?php

beforeEach(function () {
    $this->f = dashboardBoot();
});

it('AC-11: renders active students per unit and cohort', function () {
    dashboardStudentData($this->f);

    $text = dashboardWidgetText(keuanganUser('admin-sekolah'), 'students');

    expect($text)->toContain('Santri aktif')
        ->and($text)->toMatch('/\b6\b/')
        ->and($text)->toContain('Sekolah Dasar')
        ->and($text)->toContain('Sekolah Lanjutan Tingkat Pertama')
        ->and($text)->toContain('Sekolah Lanjutan Tingkat Atas')
        ->and($text)->toMatch('/Angkatan 2026\s+3\b/')
        ->and($text)->toMatch('/Angkatan 2025\s+2\b/')
        ->and($text)->toMatch('/Angkatan 2026\s+1\b/')
        ->and($text)->toContain('Belum ada santri aktif.')
        ->and($text)->not->toContain('Modul belum tersedia.');
});

it('AC-12: renders only the scoped unit for a limited user', function () {
    dashboardStudentData($this->f);

    $text = dashboardWidgetText(keuanganUser('kesantrian', ['SLTP']), 'students');

    expect($text)->toContain('Sekolah Lanjutan Tingkat Pertama')
        ->and($text)->not->toContain('Sekolah Dasar')
        ->and($text)->not->toContain('Sekolah Lanjutan Tingkat Atas');
});

it('AC-13: shows the empty state', function () {
    $text = dashboardWidgetText(keuanganUser('admin-sekolah'), 'students');

    expect($text)->toContain('Belum ada santri aktif.')
        ->and($text)->not->toContain('Modul belum tersedia.');
});

it('AC-13: links to the student list only when allowed', function () {
    $url = route('filament.admin.resources.students.index');
    $link = __('pondok.dashboard.students.list_link');

    expect($link)->toBe('Lihat data santri');

    $withLink = dashboardGet(keuanganUser('admin-sekolah'));
    expect(bayarHtmlText($withLink->getContent()))->toContain($link);
    $withLink->assertSee($url, false);

    $withoutLink = dashboardGet(dashboardUserWith(['view-dashboard', 'view-student-metrics']));
    $withoutLink->assertSeeLivewire(dashboardWidgets()['students']);
    expect(bayarHtmlText($withoutLink->getContent()))->toContain('Santri aktif')
        ->and(bayarHtmlText($withoutLink->getContent()))->not->toContain($link);
    $withoutLink->assertDontSee($url, false);
});
