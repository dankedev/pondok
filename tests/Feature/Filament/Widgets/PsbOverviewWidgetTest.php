<?php

use App\Filament\Widgets\PsbOverviewWidget;
use App\Models\AdmissionWave;
use App\Models\Setting;
use Filament\Facades\Filament;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->f = seleksiFixture();
});

it('AC-30: has a metric source and reads the applicants and waves tables', function () {
    expect(PsbOverviewWidget::hasMetricSource())->toBeTrue()
        ->and(PsbOverviewWidget::getRequiredTables())->toContain('applicants')->toContain('admission_waves')
        ->and(PsbOverviewWidget::getDashboardModule())->toBe('psb');
});

it('AC-30: shows the year heading, totals and a stat per status to admin-psb', function () {
    seleksiSummaryApplicants(2, 'diajukan');
    seleksiSummaryApplicants(1, 'diverifikasi');
    seleksiSummaryApplicants(1, 'diterima');
    seleksiSummaryApplicants(1, 'diajukan', ['created_at' => '2026-11-01 08:00:00']);
    seleksiSummaryApplicants(1, 'draft');

    $response = dashboardGet($this->f->user);
    $text = bayarHtmlText($response->getContent());

    expect(seleksiText('pondok.dashboard.psb.heading_year', ['year' => '2027/2028']))->toBe('Pendaftaran santri baru 2027/2028')
        ->and(seleksiText('pondok.dashboard.psb.total'))->toBe('Total pendaftar')
        ->and($text)->toContain('Pendaftaran santri baru 2027/2028')
        ->and(seleksiStatValue($text, seleksiText('pondok.dashboard.psb.total')))->toBe(5)
        ->and($text)->toContain(seleksiText('pondok.dashboard.psb.total_description', ['count' => 4]))
        ->and(seleksiStatValue($text, __('pondok.applicant.statuses.diajukan')))->toBe(3)
        ->and(seleksiStatValue($text, __('pondok.applicant.statuses.diverifikasi')))->toBe(1)
        ->and(seleksiStatValue($text, __('pondok.applicant.statuses.diterima')))->toBe(1)
        ->and(seleksiStatValue($text, __('pondok.applicant.statuses.cadangan')))->toBe(0)
        ->and(seleksiStatValue($text, __('pondok.applicant.statuses.ditolak')))->toBe(0)
        ->and($text)->not->toContain('Modul belum tersedia.');

    $positions = collect(['dashboard.psb.total', 'applicant.statuses.diajukan', 'applicant.statuses.diverifikasi', 'applicant.statuses.diterima', 'applicant.statuses.cadangan', 'applicant.statuses.ditolak'])
        ->map(fn (string $key): int|false => strpos($text, __("pondok.{$key}")));

    expect($positions->contains(false))->toBeFalse()
        ->and($positions->all())->toBe($positions->sort()->values()->all());
});

it('AC-30: links to the applicant list only for users who may open it', function () {
    $url = route('filament.admin.resources.applicants.index');
    $link = seleksiText('pondok.dashboard.psb.list_link');

    expect($link)->toBe('Lihat pendaftar');

    $with = dashboardGet($this->f->user);

    expect(bayarHtmlText($with->getContent()))->toContain($link);
    $with->assertSee($url, false);

    $without = dashboardGet(dashboardUserWith(['view-dashboard', 'view-psb-metrics']));

    $without->assertSeeLivewire(PsbOverviewWidget::class);
    expect(bayarHtmlText($without->getContent()))->toContain('Pendaftaran santri baru 2027/2028')->not->toContain($link);
    $without->assertDontSee($url, false);
});

it('AC-30: shows module unavailable without numbers when the psb module is off', function () {
    seleksiSummaryApplicants(2, 'diajukan');
    Setting::set('modules.psb.enabled', false);

    $text = dashboardWidgetText($this->f->user, 'psb');

    expect($text)->toContain('Modul belum tersedia.')->not->toContain(seleksiText('pondok.dashboard.psb.total'))
        ->and(PsbOverviewWidget::isModuleAvailable())->toBeFalse();
});

it('AC-30: hides the widget from roles without the psb metrics permission', function () {
    $response = dashboardGet(keuanganUser('bendahara'));

    $response->assertDontSeeLivewire(PsbOverviewWidget::class);

    expect(PsbOverviewWidget::canView())->toBeFalse();
});

it('AC-30: shows zeros and a hint when there is no admission wave', function () {
    AdmissionWave::query()->delete();

    $text = dashboardWidgetText($this->f->user, 'psb');

    expect($text)->toContain(seleksiText('pondok.dashboard.psb.heading'))
        ->and(seleksiText('pondok.dashboard.psb.no_waves'))->toBe('Belum ada gelombang pendaftaran.')
        ->and($text)->toContain('Belum ada gelombang pendaftaran.')
        ->and(seleksiStatValue($text, __('pondok.dashboard.psb.total')))->toBe(0)
        ->and(seleksiStatValue($text, __('pondok.applicant.statuses.diterima')))->toBe(0)
        ->and($text)->not->toContain('Modul belum tersedia.');
});
