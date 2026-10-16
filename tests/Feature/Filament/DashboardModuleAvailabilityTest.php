<?php

use App\Actions\Dashboard\SummarizeActiveStudents;
use App\Actions\Dashboard\SummarizeCurrentMonthFinance;
use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = dashboardBoot();
});

it('AC-4: shows module unavailable when keuangan is disabled', function () {
    dashboardFinanceData($this->f);
    Setting::set('modules.keuangan.enabled', false);
    $this->mock(SummarizeCurrentMonthFinance::class, fn ($mock) => $mock->shouldNotReceive('handle'));

    $response = dashboardGet(keuanganUser('bendahara'))->assertSeeLivewire(dashboardWidgets()['finance']);
    $text = bayarHtmlText($response->getContent());

    expect($text)->toContain('Modul belum tersedia.')
        ->and($text)->not->toContain('Ditagihkan')
        ->and($text)->not->toContain('Rp')
        ->and($text)->not->toContain(__('pondok.dashboard.finance.report_link'));
    $response->assertDontSee(route('filament.admin.pages.finance-report'), false);
});

it('AC-4: shows module unavailable when akademik is disabled', function () {
    dashboardStudentData($this->f);
    Setting::set('modules.akademik.enabled', false);
    $this->mock(SummarizeActiveStudents::class, fn ($mock) => $mock->shouldNotReceive('handle'));

    $response = dashboardGet(keuanganUser('admin-sekolah'))->assertSeeLivewire(dashboardWidgets()['students']);
    $text = bayarHtmlText($response->getContent());

    expect($text)->toContain('Modul belum tersedia.')
        ->and($text)->not->toContain('Lihat data santri')
        ->and($text)->not->toContain('Angkatan 2025');
});

it('AC-5: still renders when a finance table is missing', function () {
    Schema::disableForeignKeyConstraints();
    Schema::drop('payment_allocations');
    Schema::enableForeignKeyConstraints();

    $response = dashboardGet(keuanganUser('bendahara'))->assertSeeLivewire(dashboardWidgets()['finance']);

    expect(bayarHtmlText($response->getContent()))->toContain('Modul belum tersedia.')
        ->and(bayarHtmlText($response->getContent()))->not->toContain('Ditagihkan');
});

it('AC-5: still renders when a student table is missing', function () {
    Schema::disableForeignKeyConstraints();
    Schema::drop('cohorts');
    Schema::enableForeignKeyConstraints();

    $response = dashboardGet(keuanganUser('kesantrian'))->assertSeeLivewire(dashboardWidgets()['students']);

    expect(bayarHtmlText($response->getContent()))->toContain('Modul belum tersedia.');
});

it('AC-6: shows module unavailable for modules that are not built yet (:state)', function (bool $enabled) {
    foreach (['psb', 'konten', 'dokumen', 'prestasi'] as $module) {
        Setting::set("modules.{$module}.enabled", $enabled);
    }

    $this->actingAs(keuanganUser('super-admin'));
    $headings = [
        'psb' => __('pondok.dashboard.psb.heading'),
        'content' => __('pondok.dashboard.content.heading'),
        'documents' => __('pondok.dashboard.documents.heading'),
        'achievements' => __('pondok.dashboard.achievements.heading'),
    ];

    foreach ($headings as $key => $heading) {
        // F011: widget PSB punya sumber metrik; "Modul belum tersedia" hanya berlaku saat saklar psb mati.
        if ($key === 'psb' && $enabled) {
            continue;
        }

        // F016: widget konten punya sumber metrik; "Modul belum tersedia" hanya berlaku saat saklar konten mati.
        if ($key === 'content' && $enabled) {
            continue;
        }

        // F015: widget prestasi punya sumber metrik; "Modul belum tersedia" hanya berlaku saat saklar prestasi mati.
        if ($key === 'achievements' && $enabled) {
            continue;
        }

        $class = dashboardWidgets()[$key];

        expect(class_exists($class))->toBeTrue("Kelas widget {$class} belum ada")
            ->and($class::isModuleAvailable())->toBeFalse();

        Livewire::test($class)
            ->assertSee($heading)
            ->assertSee('Modul belum tersedia.')
            ->assertDontSeeHtml('<a ');
    }

    $text = dashboardText(keuanganUser('super-admin'));

    expect(substr_count($text, 'Modul belum tersedia.'))->toBeGreaterThanOrEqual($enabled ? 1 : 4);
})->with([true, false]);
