<?php

use App\Actions\Dashboard\SummarizeSetupProgress;
use App\Filament\Pages\Settings\Identity;
use App\Filament\Widgets\SetupChecklistWidget;
use App\Models\Setting;
use Database\Seeders\InstallSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(InstallSeeder::class);
    Filament::setCurrentPanel('admin');
});

it('AC-1: shows super-admin the checklist with progress, buttons and guide links', function () {
    $response = test()->actingAs(keuanganUser('super-admin'))->get('/admin')->assertOk();
    $response->assertSeeLivewire(SetupChecklistWidget::class);

    $html = Livewire::test(SetupChecklistWidget::class)->html();
    $text = bayarHtmlText($html);

    expect($text)->toContain('Setup awal')
        ->and($text)->toMatch('/\d+ dari 10 langkah selesai/')
        ->and($text)->toContain('Profil lembaga')
        ->and($text)->toContain('Cron dan antrean')
        ->and($html)->toContain('href="'.Identity::getUrl().'"')
        ->and($html)->toContain('href="https://pondok.org/docs/modul/"')
        ->and($html)->toContain('href="https://pondok.org/fitur/"');
});

it('AC-3: leaves out the steps of a disabled module', function () {
    Setting::set('modules.keuangan.enabled', false);
    test()->actingAs(keuanganUser('super-admin'));

    expect(bayarHtmlText(Livewire::test(SetupChecklistWidget::class)->html()))
        ->not->toContain('Tarif biaya')
        ->toMatch('/\d+ dari 9 langkah selesai/');
});

it('AC-4: hides the widget from :role', function (string $role) {
    test()->actingAs(keuanganUser($role))->get('/admin')->assertOk()
        ->assertDontSeeLivewire(SetupChecklistWidget::class);
})->with(['admin-sekolah', 'bendahara', 'kesantrian']);

it('AC-5: hides the widget at 100%', function () {
    $this->mock(SummarizeSetupProgress::class)
        ->shouldReceive('handle')
        ->andReturn(['steps' => [['key' => 'units', 'done' => true]], 'done_count' => 1, 'total' => 1, 'percent' => 100]);

    test()->actingAs(keuanganUser('super-admin'))->get('/admin')->assertOk()
        ->assertDontSeeLivewire(SetupChecklistWidget::class);
});

it('AC-6: links every step to an existing pondok.org/docs page', function () {
    // Halaman yang ada di content/docs repo pondok-marketing (27 Okt 2026).
    $pages = ['backup', 'cron-antrean', 'data-demo', 'docker', 'easypanel', 'https-pemeriksaan', 'import-data', 'instalasi-cpanel',
        'keamanan-akun', 'kebutuhan-server', 'konfigurasi-env', 'masalah-umum', 'modul', 'pembaruan', 'pengembangan', 'pengenalan',
        'portal-wali', 'rilis'];

    $steps = array_column(app(SummarizeSetupProgress::class)->handle()['steps'], 'key');

    expect(array_keys(SetupChecklistWidget::DOCS))->toEqualCanonicalizing($steps)
        ->and(array_diff(SetupChecklistWidget::DOCS, $pages))->toBe([]);
});
