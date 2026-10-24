<?php

use App\Enums\ViolationCategory;
use App\Models\AcademicYear;
use App\Models\Setting;

beforeEach(function () {
    $this->f = waliBoot();
    Setting::set('modules.kedisiplinan.enabled', true);

    $f = $this->f;
    $f->prevYear = AcademicYear::factory()->create(['name' => '2025/2026', 'starts_on' => '2025-07-01', 'ends_on' => '2026-06-30']);
    $f->ringan = disiplinType('Terlambat shalat berjamaah', 'ringan', 5);
    $f->sedang = disiplinType('Keluar pondok tanpa izin', 'sedang', 25);
    $f->berat = disiplinType('Berkelahi', 'berat', 50);
    $f->mencuri = disiplinType('Mencuri barang teman', 'berat', 75);
    $f->pakaian = disiplinType('Pakaian tidak sesuai aturan', 'ringan', 7);
});

/**
 * Catatan s1: ringan 10 Okt (5) + sedang 12 Okt (25) = 30 poin, deskripsi rahasia pada salah satunya;
 * tidak ikut tampil: batal (berkelahi), tahun ajaran lalu (mencuri), anak lain (pakaian).
 */
function waliDisciplineData(object $f): void
{
    disiplinRaw($f, $f->s1, $f->ringan, ['occurred_on' => '2026-10-10', 'description' => 'RAHASIA-DESKRIPSI-WALI']);
    disiplinRaw($f, $f->s1, $f->sedang, ['occurred_on' => '2026-10-12']);
    disiplinRaw($f, $f->s1, $f->berat, ['occurred_on' => '2026-10-13', 'cancelled_at' => now(), 'cancel_reason' => 'Salah catat']);
    disiplinRaw($f, $f->s1, $f->mencuri, ['academic_year_id' => $f->prevYear->id, 'occurred_on' => '2026-03-02']);
    disiplinRaw($f, $f->o1, $f->pakaian, ['occurred_on' => '2026-10-11']);
}

it('AC-11: shows the total points and the list of the own child for the active year only', function () {
    $f = $this->f;
    waliDisciplineData($f);

    $text = waliText($f->wali, "/wali/santri/{$f->s1->id}");

    expect($text)->toContain('Kedisiplinan')
        ->toContain('Terlambat shalat berjamaah')->toContain('Keluar pondok tanpa izin')
        ->toContain('10 Okt 2026')->toContain('12 Okt 2026')
        ->toContain(ViolationCategory::Ringan->label())->toContain(ViolationCategory::Sedang->label())
        ->and(preg_match('/\b30\b/u', $text))->toBe(1)
        ->and(preg_match('/\b80\b/u', $text))->toBe(0)
        ->and(preg_match('/\b105\b/u', $text))->toBe(0)
        ->and($text)->not->toContain('Berkelahi')->not->toContain('Mencuri barang teman')->not->toContain('Pakaian tidak sesuai aturan')
        ->not->toContain('13 Okt 2026')->not->toContain('2 Mar 2026');
});

it('AC-11: never renders the description or the cancel reason in the HTML', function () {
    $f = $this->f;
    waliDisciplineData($f);

    $html = $this->actingAs($f->wali)->get("/wali/santri/{$f->s1->id}")->assertOk()->getContent();

    expect($html)->not->toContain('RAHASIA-DESKRIPSI-WALI')->not->toContain('Salah catat');
});

it('AC-11: keeps a record of one child away from a sibling and from another wali', function () {
    $f = $this->f;
    waliDisciplineData($f);

    expect(waliText($f->wali, "/wali/santri/{$f->s2->id}"))->not->toContain('Terlambat shalat berjamaah')->toContain('Tidak ada catatan pelanggaran');

    $this->actingAs($f->wali)->get("/wali/santri/{$f->o1->id}")->assertNotFound();
    expect(waliText($f->other, "/wali/santri/{$f->o1->id}"))->toContain('Pakaian tidak sesuai aturan')->not->toContain('Terlambat shalat berjamaah');
});

it('AC-11: answers 200 with the empty message when there is no active academic year', function () {
    $f = $this->f;
    waliDisciplineData($f);
    AcademicYear::query()->update(['is_active' => false]);

    $text = waliText($f->wali, "/wali/santri/{$f->s1->id}");

    expect($text)->toContain('Ahmad Satu')->toContain('Tidak ada catatan pelanggaran')->not->toContain('Terlambat shalat berjamaah');
});

it('AC-1: hides the discipline section when the module is off, keeps the page open and the data', function () {
    $f = $this->f;
    waliDisciplineData($f);

    expect(waliText($f->wali, "/wali/santri/{$f->s1->id}"))->toContain('Kedisiplinan');

    Setting::set('modules.kedisiplinan.enabled', false);

    expect(waliText($f->wali, "/wali/santri/{$f->s1->id}"))->not->toContain('Kedisiplinan')->not->toContain('Terlambat shalat berjamaah')->toContain('Ahmad Satu')
        ->and(disiplinCount())->toBe(5);
});
