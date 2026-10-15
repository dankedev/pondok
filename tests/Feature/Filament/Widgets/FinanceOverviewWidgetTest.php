<?php

use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->f = dashboardBoot();
});

it('AC-7: renders current month finance', function () {
    dashboardFinanceData($this->f);

    $text = dashboardText(keuanganUser('bendahara'));

    expect($text)->toContain('Keuangan Oktober 2026')
        ->and($text)->toContain('Rp 1.000.000')
        ->and($text)->toContain('Rp 600.000')
        ->and($text)->toContain('60% terkumpul')
        ->and($text)->toContain('Rp 200.000')
        ->and($text)->toContain('3 tagihan')
        ->and($text)->toContain('1 santri')
        ->and($text)->toContain('2 pembayaran')
        ->and($text)->not->toContain('Modul belum tersedia.');
});

it('AC-7: shows the new month after the Jakarta month boundary', function () {
    dashboardFinanceData($this->f);
    $this->travelTo(CarbonImmutable::parse('2026-10-31 20:00', 'UTC'));

    $text = dashboardText(keuanganUser('bendahara'));

    expect($text)->toContain('Keuangan November 2026')
        ->and($text)->not->toContain('Rp 1.000.000');
});

it('AC-8: renders the empty month', function () {
    $text = dashboardText(keuanganUser('bendahara'));

    expect(preg_match_all('/Rp 0(?![.\d])/u', $text))->toBeGreaterThanOrEqual(4)
        ->and($text)->toContain('Belum ada tagihan untuk bulan ini.')
        ->and($text)->not->toContain('% terkumpul')
        ->and($text)->not->toContain('Modul belum tersedia.');
});

it('AC-9: renders only the scoped unit for a limited bendahara', function () {
    dashboardFinanceData($this->f, 'slta');

    $text = dashboardText(keuanganUser('bendahara', ['SLTP']));

    expect($text)->toContain('Rp 800.000')
        ->and($text)->toContain('2 tagihan')
        ->and($text)->not->toContain('Rp 1.000.000');
});

it('AC-10: links to the finance report only when allowed', function () {
    $url = route('filament.admin.pages.finance-report');
    $link = __('pondok.dashboard.finance.report_link');

    expect($link)->toBe('Lihat laporan keuangan');

    $withLink = dashboardGet(keuanganUser('bendahara'));
    expect(bayarHtmlText($withLink->getContent()))->toContain($link);
    $withLink->assertSee($url, false);

    $withoutLink = dashboardGet(dashboardUserWith(['view-dashboard', 'view-finance-metrics']));
    $withoutLink->assertSeeLivewire(dashboardWidgets()['finance']);
    expect(bayarHtmlText($withoutLink->getContent()))->toContain('Keuangan Oktober 2026')
        ->and(bayarHtmlText($withoutLink->getContent()))->not->toContain($link);
    $withoutLink->assertDontSee($url, false);
});
