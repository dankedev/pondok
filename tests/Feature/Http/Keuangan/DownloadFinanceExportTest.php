<?php

use App\Actions\Keuangan\ExportFinanceReportCsv;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    Storage::fake('private');
    $this->f = laporanFixture();
    laporanInvoice($this->f, laporanStudent($this->f, attributes: ['name' => 'Santri Unduh']), '2026-10-01', 100000, 0, null, '2026-10-10');
    $this->owner = keuanganUser('bendahara');
    $this->path = app(ExportFinanceReportCsv::class)->handle(laporanFilter(), 'tunggakan', $this->owner);
    $this->uuid = basename(dirname($this->path));
});

function exportUrl(string $uuid): string
{
    return route('filament.admin.keuangan.finance-export', $uuid, absolute: false);
}

it('AC-9: lets the trigger download the file and logs the download', function () {
    $response = $this->actingAs($this->owner)->get(exportUrl($this->uuid))->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('laporan-keuangan-tunggakan-2026-10-2026-10.csv')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->streamedContent())->toBe(Storage::disk('private')->get($this->path));

    $activity = Activity::query()->where('log_name', 'keuangan')->where('event', 'downloaded')->sole();

    expect($activity->causer->is($this->owner))->toBeTrue()
        ->and($activity->properties['export'])->toBe($this->uuid);
});

it('AC-10: hides the export from another permitted user, unknown ids, and guests', function () {
    $this->actingAs(keuanganUser('bendahara'))->get(exportUrl($this->uuid))->assertNotFound();
    $this->actingAs($this->owner)->get(exportUrl((string) Str::uuid()))->assertNotFound();

    auth()->logout();
    $this->get(exportUrl($this->uuid))->assertRedirect(route('filament.admin.auth.login'));

    expect(Activity::query()->where('event', 'downloaded')->count())->toBe(0);
});

it('AC-11: forbids a trigger without the permission and a disabled keuangan module', function () {
    $sekolah = keuanganUser('admin-sekolah');
    $path = app(ExportFinanceReportCsv::class)->handle(laporanFilter(), 'ringkasan', $sekolah);
    $this->actingAs($sekolah)->get(exportUrl(basename(dirname($path))))->assertForbidden();

    Setting::set('modules.keuangan.enabled', false);
    $this->actingAs($this->owner)->get(exportUrl($this->uuid))->assertForbidden();

    expect(Activity::query()->where('event', 'downloaded')->count())->toBe(0);
});
