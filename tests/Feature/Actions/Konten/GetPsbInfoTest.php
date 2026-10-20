<?php

use App\Actions\Konten\GetPsbInfo;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionWave;
use App\Models\Applicant;
use App\Models\FeeType;
use App\Models\Setting;
use Illuminate\Support\HtmlString;

/*
| F038 AC-3 sampai AC-9 dan AC-13. Kontrak: `App\Actions\Konten\GetPsbInfo::handle(): array` dengan kunci
| page (?StaticPage), bodyHtml (?HtmlString), waves, requirements, fees, contacts (masing-masing `[]` bila kosong).
| Tanggal uji 2026-10-15; modul psb aktif.
*/

beforeEach(function () {
    $this->f = psbInfoBoot();
});

function psbInfoGet(): array
{
    app()->forgetScopedInstances();

    return app(GetPsbInfo::class)->handle();
}

/**
 * Gelombang dibuka dengan tarif untuk SD dan SLTP pada jenis biaya `saat_daftar` dan `bulanan`.
 */
function psbInfoSeedFees(object $f): AdmissionWave
{
    Setting::set('modules.keuangan.enabled', true);
    $wave = psbInfoWave();
    $registration = psbFeeType('saat_daftar', ['name' => 'Formulir']);
    $monthly = FeeType::factory()->monthly()->create(['name' => 'SPP']);

    foreach ([$f->sd, $f->sltp] as $unit) {
        keuanganRate($registration, $wave->academicYear, $unit, 150000);
        keuanganRate($monthly, $wave->academicYear, $unit, 500000);
    }

    return $wave;
}

it('AC-3: returns empty sections on a fresh install', function () {
    expect(psbInfoGet())->toMatchArray(['page' => null, 'bodyHtml' => null, 'waves' => [], 'requirements' => [], 'fees' => [], 'contacts' => []]);
});

it('AC-4: uses only the published psb-info page with slug info-psb and sanitizes its body', function () {
    $page = psbInfoPage(['body' => '<p>Syarat umum</p><script>alert(1)</script><img src="x" onerror="alert(1)">']);
    $data = psbInfoGet();

    expect($data['page']->is($page))->toBeTrue()
        ->and($data['bodyHtml'])->toBeInstanceOf(HtmlString::class)
        ->and($data['bodyHtml']->toHtml())->toContain('Syarat umum')->not->toContain('<script')->not->toContain('onerror');

    $page->update(['body' => '']);
    $empty = psbInfoGet();
    expect($empty['page']->is($page))->toBeTrue()->and($empty['bodyHtml'])->toBeNull();

    $page->update(['status' => 'draft', 'body' => '<p>Rahasia</p>']);
    expect(psbInfoGet())->toMatchArray(['page' => null, 'bodyHtml' => null]);

    $page->update(['status' => 'terbit', 'template' => 'default']);
    expect(psbInfoGet())->toMatchArray(['page' => null, 'bodyHtml' => null]);
});

it('AC-5: returns open and upcoming waves only', function () {
    psbInfoWave('Dibuka');
    psbInfoWave('Mendatang', '2026-11-01', '2026-11-30');
    psbInfoWave('Lewat', '2026-08-01', '2026-09-30');
    psbInfoWave('Ditutup', '2026-10-01', '2026-12-31', ['is_open' => false]);
    psbInfoWave('Mendatang Ditutup', '2026-12-01', '2026-12-31', ['is_open' => false]);
    $full = psbInfoWave('Penuh', '2026-10-01', '2026-12-31', ['quota' => 1]);
    Applicant::factory()->forWave($full)->create();

    expect(psbInfoGet()['waves'])->toHaveCount(2);
});

it('AC-8: has no fees without finance, without an open wave, or without rates, and never queries fee_rates when finance is off', function () {
    psbInfoSeedFees($this->f);
    expect(psbInfoGet()['fees'])->not->toBe([]);

    Setting::set('modules.keuangan.enabled', false);
    $queries = recordedQueries(fn () => expect(psbInfoGet()['fees'])->toBe([]));
    expect(queriesOnTable($queries, 'fee_rates'))->toBe(0);

    Setting::set('modules.keuangan.enabled', true);
    AdmissionWave::query()->update(['is_open' => false]);
    expect(psbInfoGet()['fees'])->toBe([]);

    AdmissionWave::query()->update(['is_open' => true]);
    FeeType::query()->update(['is_active' => false]);
    expect(psbInfoGet()['fees'])->toBe([]);
});

it('AC-9: returns only contacts flagged for psb', function () {
    psbInfoSetContacts([
        profilContact(['key' => 'aaaa1111', 'label' => 'Panitia', 'is_psb' => true]),
        profilContact(['key' => 'bbbb2222', 'label' => 'Sekretariat', 'is_psb' => false]),
        profilContact(['key' => 'cccc3333', 'label' => 'Lama']),
        profilContact(['key' => 'dddd4444', 'label' => 'Panitia Dua', 'is_psb' => true, 'is_whatsapp' => false]),
    ]);

    expect(psbInfoGet()['contacts'])->toHaveCount(2);
});

it('AC-13: query count does not grow with waves, requirements, and contacts', function () {
    psbInfoWave('Gelombang 1');
    AdmissionRequirement::factory()->create(['unit_id' => $this->f->sd->id, 'name' => 'Akta']);
    psbInfoSetContacts([profilContact(['is_psb' => true])]);
    psbInfoGet();
    $small = dashboardQueryCount(fn () => psbInfoGet());

    foreach (range(2, 5) as $i) {
        psbInfoWave("Gelombang {$i}", '2026-10-01', '2026-12-31');
    }
    psbInfoWave('Mendatang', '2026-11-01', '2026-11-30');
    foreach (range(1, 9) as $i) {
        AdmissionRequirement::factory()->create(['unit_id' => $i % 2 ? $this->f->sltp->id : null, 'name' => "Berkas {$i}"]);
    }
    psbInfoSetContacts(array_map(fn (int $i): array => profilContact(['key' => "kkkk000{$i}", 'is_psb' => true]), [1, 2, 3]));

    expect(dashboardQueryCount(fn () => psbInfoGet()))->toBe($small);
});

it('AC-13: the fee section costs at most 2 + 2 queries per unit and fee type pair', function () {
    psbInfoWave('Gelombang Awal');
    Setting::set('modules.keuangan.enabled', false);
    $without = dashboardQueryCount(fn () => psbInfoGet());

    psbInfoSeedFees($this->f);
    psbInfoGet();
    $with = dashboardQueryCount(fn () => psbInfoGet());

    // 3 jenjang x 2 jenis biaya, walau SLTA tanpa tarif.
    expect($with - $without)->toBeLessThanOrEqual(2 + 2 * 3 * 2);
});
