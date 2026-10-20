<?php

use App\Models\AdmissionRequirement;
use App\Models\AdmissionWave;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

/*
| F038 AC-2 sampai AC-9 dan AC-13. Route `psb.info` = `/info-psb` (berdiri sendiri, nama bukan `portal.psb.info`).
| Teks yang diasumsikan: badge "Dibuka" dan "Akan dibuka", label "Wajib" dan "Opsional", "Semua jenjang",
| rupiah "Rp 1.500.000", tanggal "1 Oktober 2026", `h1` bawaan "Info PSB". Empty state memakai `x-ui.empty-state` (kelas `border-dashed`).
*/

beforeEach(function () {
    $this->f = psbInfoBoot();
});

function psbInfoHtml(): string
{
    return test()->get(route('psb.info'))->assertOk()->getContent();
}

it('AC-2: serves /info-psb without a page or data, and stays 404 when psb is off', function () {
    expect(route('psb.info', absolute: false))->toBe('/info-psb');

    $this->get('/info-psb')->assertOk();

    psbInfoPage(['status' => 'terbit']);
    Setting::set('modules.psb.enabled', false);
    $this->get('/info-psb')->assertNotFound();
});

it('AC-2: stays available when the konten module is off', function () {
    Setting::set('modules.konten.enabled', false);

    $this->get('/info-psb')->assertOk();
});

it('AC-2: uses the stateless portal headers like the home page', function () {
    $home = $this->get('/')->assertOk();
    $guest = $this->get('/info-psb')->assertOk();
    $signedIn = $this->actingAs(User::factory()->create())->withUnencryptedCookie('laravel_session', 'abc')->get('/info-psb')->assertOk();

    expect($guest->headers->getCookies())->toBeEmpty()->and($signedIn->headers->getCookies())->toBeEmpty()
        ->and($guest->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=300')
        ->and($guest->headers->get('ETag'))->not->toBeNull()
        ->and($guest->headers->get('X-Content-Type-Options'))->toBe($home->headers->get('X-Content-Type-Options'))
        ->and($guest->headers->get('Content-Security-Policy'))->toBe($home->headers->get('Content-Security-Policy'));
    expect(strtolower($guest->getContent()))->not->toContain('csrf-token')->not->toContain('livewire');
});

it('AC-2: lists the menu item for the page and marks it active only while psb is on', function () {
    navSet(null);

    $links = portalNavLinks($this->get('/info-psb')->assertOk()->getContent())['desktop'];
    $item = collect($links)->firstWhere('text', 'Info PSB');

    expect($item)->not->toBeNull()->and($item['href'])->toBe(route('psb.info'))->and($item['current'])->toBeTrue();

    Setting::set('modules.psb.enabled', false);
    $off = portalNavLinks($this->get('/')->assertOk()->getContent())['desktop'];

    expect(collect($off)->pluck('text'))->not->toContain('Info PSB');
});

it('AC-3: shows one h1 from the menu label and an empty state on a fresh install', function () {
    $html = psbInfoHtml();

    expect(psbInfoCountTag($html, 'h1'))->toBe(1)
        ->and(preg_match('#<h1[^>]*>\s*Info PSB\s*</h1>#', $html))->toBe(1)
        ->and($html)->toContain('border-dashed')
        ->and(psbInfoCountTag($html, 'h2'))->toBe(0);
});

it('AC-4: shows the page title as h1 with sanitized body, keeps the title for an empty body, ignores a draft', function () {
    $page = psbInfoPage(['body' => '<p>Syarat umum pendaftaran</p><script>alert(1)</script><img src="x" onerror="alert(1)"><a href="javascript:alert(1)">klik</a>']);
    $html = psbInfoHtml();

    expect(preg_match('#<h1[^>]*>\s*Informasi Pendaftaran\s*</h1>#', $html))->toBe(1)
        ->and($html)->toContain('Syarat umum pendaftaran')->not->toContain('alert(1)')->not->toContain('onerror')->not->toContain('javascript:');

    $page->update(['body' => '']);
    $html = psbInfoHtml();
    expect(preg_match('#<h1[^>]*>\s*Informasi Pendaftaran\s*</h1>#', $html))->toBe(1)
        ->and($html)->not->toContain('portal-prose');

    $page->update(['status' => 'draft', 'body' => '<p>Isi rahasia</p>']);
    $html = psbInfoHtml();
    expect($html)->not->toContain('Informasi Pendaftaran')->not->toContain('Isi rahasia')
        ->and(preg_match('#<h1[^>]*>\s*Info PSB\s*</h1>#', $html))->toBe(1);
});

it('AC-5: shows open and upcoming waves with status and dates, hides the rest, and offers Daftar only when one is open', function () {
    psbInfoWave('Gelombang Buka', '2026-10-01', '2026-12-31');
    psbInfoWave('Gelombang Nanti', '2027-01-05', '2027-02-28');
    psbInfoWave('Gelombang Lewat', '2026-08-01', '2026-09-30');
    psbInfoWave('Gelombang Tutup', '2026-10-02', '2026-12-30', ['is_open' => false]);
    $html = psbInfoHtml();
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    expect($text)->toContain('Gelombang Buka')->toContain('Dibuka')->toContain('2026/2027')->toContain('1 Oktober 2026')->toContain('31 Desember 2026')
        ->toContain('Gelombang Nanti')->toContain('Akan dibuka')->toContain('5 Januari 2027')
        ->not->toContain('Gelombang Lewat')->not->toContain('Gelombang Tutup')
        ->and($html)->toContain('href="'.route('psb.register').'"');

    AdmissionWave::query()->where('name', 'Gelombang Buka')->update(['is_open' => false]);
    $upcomingOnly = psbInfoHtml();
    expect($upcomingOnly)->toContain('Gelombang Nanti')->not->toContain('href="'.route('psb.register').'"');
});

it('AC-6: lists requirements per unit with all-unit first, by sort order, with required and optional text, regardless of unit scope', function () {
    AdmissionRequirement::factory()->create(['unit_id' => $this->f->sd->id, 'name' => 'Ijazah TK', 'sort_order' => 2]);
    AdmissionRequirement::factory()->create(['unit_id' => $this->f->sd->id, 'name' => 'Akta Kelahiran', 'sort_order' => 1]);
    AdmissionRequirement::factory()->create(['unit_id' => null, 'name' => 'Kartu Keluarga', 'sort_order' => 5, 'is_required' => true]);
    AdmissionRequirement::factory()->create(['unit_id' => $this->f->sltp->id, 'name' => 'Surat Sehat', 'is_required' => false]);
    $this->seed(RolePermissionSeeder::class);
    $this->actingAs(keuanganUser('bendahara', ['SLTA']));
    $html = psbInfoHtml();

    expect(psbInfoPosition($html, 'Semua jenjang'))->toBeLessThan(psbInfoPosition($html, 'Kartu Keluarga'))
        ->and(psbInfoPosition($html, 'Kartu Keluarga'))->toBeLessThan(psbInfoPosition($html, 'Sekolah Dasar'))
        ->and(psbInfoPosition($html, 'Akta Kelahiran'))->toBeLessThan(psbInfoPosition($html, 'Ijazah TK'))
        ->and(psbInfoPosition($html, 'Surat Sehat'))->not->toBeFalse()
        ->and(strip_tags($html))->toContain('Wajib')->toContain('Opsional')
        ->and(psbInfoCountTag($html, 'h2'))->toBe(1);
});

/**
 * Gelombang dibuka dengan tarif SD (formulir 150.000, SPP 1.500.000) dan keuangan aktif.
 */
function psbInfoFeeSetup(object $f): void
{
    Setting::set('modules.keuangan.enabled', true);
    $wave = psbInfoWave();
    $registration = psbFeeType('saat_daftar', ['name' => 'Formulir Pendaftaran']);
    $monthly = FeeType::factory()->monthly()->create(['name' => 'SPP Bulanan']);
    keuanganRate($registration, $wave->academicYear, $f->sd, 150000);
    keuanganRate($monthly, $wave->academicYear, $f->sd, 1500000);
}

it('AC-7: shows fees per unit for the open wave with wave rates winning and whole-rupiah format', function () {
    psbInfoFeeSetup($this->f);
    $wave = AdmissionWave::query()->firstOrFail();
    $registration = FeeType::query()->where('name', 'Formulir Pendaftaran')->firstOrFail();
    keuanganRate($registration, $wave->academicYear, $this->f->sd, 100000, waveId: $wave->id);
    $html = psbInfoHtml();
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    expect($text)->toContain('Sekolah Dasar')->toContain('Formulir Pendaftaran')->toContain('Rp 100.000')->not->toContain('Rp 150.000')
        ->toContain('SPP Bulanan')->toContain('Rp 1.500.000')->not->toContain('Rp 1.500.000,')->not->toContain('Sekolah Lanjutan Tingkat Pertama');
});

it('AC-8: hides the fee section when a condition is not met and skips units without a rate', function () {
    psbInfoFeeSetup($this->f);
    expect(psbInfoHtml())->toContain('Rp 1.500.000');

    $conditions = [
        'finance off' => [fn () => Setting::set('modules.keuangan.enabled', false), fn () => Setting::set('modules.keuangan.enabled', true)],
        'inactive types' => [fn () => FeeType::query()->update(['is_active' => false]), fn () => FeeType::query()->update(['is_active' => true])],
        'no open wave' => [fn () => AdmissionWave::query()->update(['is_open' => false]), fn () => AdmissionWave::query()->update(['is_open' => true])],
        'yearly and manual only' => [fn () => FeeType::query()->update(['frequency' => 'tahunan']), function () {
            FeeType::query()->where('name', 'SPP Bulanan')->update(['frequency' => 'bulanan']);
            FeeType::query()->where('name', 'Formulir Pendaftaran')->update(['frequency' => 'saat_daftar']);
        }],
        'no rates' => [fn () => FeeRate::query()->delete(), null],
    ];

    foreach ($conditions as $name => [$apply, $restore]) {
        $apply();
        $text = strip_tags(psbInfoHtml());
        expect($text)->not->toContain('Rp 1.500.000', $name)->not->toContain('Rp 150.000', $name);
        $restore?->__invoke();
    }
});

it('AC-9: shows only psb flagged contacts with formatted phone and a WhatsApp link when flagged', function () {
    psbInfoSetContacts([
        profilContact(['key' => 'aaaa1111', 'label' => 'Panitia PSB', 'name' => 'Ust. Ahmad', 'phone' => '081200000001', 'is_whatsapp' => true, 'is_psb' => true]),
        profilContact(['key' => 'bbbb2222', 'label' => 'Bagian Telepon', 'phone' => '081200000002', 'is_whatsapp' => false, 'is_psb' => true]),
        profilContact(['key' => 'cccc3333', 'label' => 'Sekretariat Umum', 'phone' => '081200000003', 'is_psb' => false]),
        profilContact(['key' => 'dddd4444', 'label' => 'Kontak Lama', 'phone' => '081200000004']),
    ]);
    $html = psbInfoHtml();
    $text = strip_tags($html);

    expect($text)->toContain('Panitia PSB')->toContain('Ust. Ahmad')->toContain('0812 0000 0001')->toContain('Bagian Telepon')->toContain('0812 0000 0002')
        ->not->toContain('Sekretariat Umum')->not->toContain('Kontak Lama')
        ->and($html)->toContain('href="https://wa.me/6281200000001"')->not->toContain('wa.me/6281200000002');

    psbInfoSetContacts([profilContact(['is_psb' => false])]);
    expect(strip_tags(psbInfoHtml()))->not->toContain('Sekretariat');
});

it('AC-13: renders one h1, one h2 per section, and no accent text color', function () {
    psbInfoPage(['body' => '<p>Teks</p>']);
    psbInfoFeeSetup($this->f);
    psbInfoWave('Gelombang Nanti', '2027-01-05', '2027-02-28');
    AdmissionRequirement::factory()->create(['unit_id' => null, 'name' => 'Kartu Keluarga']);
    psbInfoSetContacts([profilContact(['is_psb' => true])]);
    $html = psbInfoHtml();

    // Gelombang, syarat, biaya, kontak.
    expect(psbInfoCountTag($html, 'h1'))->toBe(1)
        ->and(psbInfoCountTag($html, 'h2'))->toBe(4)
        ->and($html)->not->toMatch('/text-accent-/')
        ->and($html)->not->toContain('border-dashed');
});
