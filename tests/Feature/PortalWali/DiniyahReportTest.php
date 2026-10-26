<?php

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/*
| Kontrak: bagian "Rapor diniyah" (`pondok.diniyah.portal.title`) di `/wali/santri/{student}` berisi tautan
| `/wali/santri/{student}/rapor/{year}/{semester}` untuk tiap semester yang punya nilai, hanya bila Akademik aktif.
| Route rapor: 404 untuk bukan anak wali, semester tanpa nilai, santri tanpa enrollment tahun itu, atau modul mati.
*/

beforeEach(function () {
    $this->f = diniyahPortalFixture();
    $f = $this->f;
    diniyahGrade($f->s1, $f->year, 1, $f->aqidah, 92);
    diniyahGrade($f->s1, $f->year, 1, $f->fiqih, 78);
    diniyahNote($f->s1, $f->year, 1, 'Pertahankan semangat belajar');
    diniyahGrade($f->o1, $f->year, 1, $f->aqidah, 55);
    $this->url = "/wali/santri/{$f->s1->id}/rapor/{$f->year->id}/1";
});

it('AC-10: shows the report section with a link only for semesters that have scores', function () {
    $f = $this->f;
    $response = $this->actingAs($f->wali)->get("/wali/santri/{$f->s1->id}")->assertOk();
    $text = bayarHtmlText($response->getContent());

    expect($text)->toContain(__('pondok.diniyah.portal.title'))
        ->and($response->getContent())->toContain($this->url)
        ->not->toContain("/rapor/{$f->year->id}/2")
        ->not->toContain('/admin/akademik/rapor');

    $other = bayarHtmlText($this->actingAs($f->wali)->get("/wali/santri/{$f->s2->id}")->assertOk()->getContent());

    expect($other)->not->toContain(__('pondok.diniyah.portal.title'));
});

it('AC-10: opens the report of the own child with scores, predicates, average, note and homeroom teacher', function () {
    $f = $this->f;
    $response = $this->actingAs($f->wali)->get($this->url)->assertOk();
    $text = bayarHtmlText($response->getContent());

    expect($text)->toContain('Ahmad Satu')->toContain('7A')->toContain('Aqidah')->toContain('Fiqih')
        ->toContain('92')->toContain('78')
        ->toContain(__('pondok.diniyah.predicates.mumtaz'))
        ->toContain(__('pondok.diniyah.predicates.jayyid'))
        ->toContain('85,0')
        ->toContain('Pertahankan semangat belajar')
        ->toContain('Ustadz Hasan')
        ->not->toContain('Citra Lain')
        ->not->toMatch('/pondok\.diniyah\b/')
        ->not->toMatch('/peringkat|rangking|ranking/i');
});

it('AC-10: answers 404 for the report of a student of another wali', function () {
    $f = $this->f;

    $this->actingAs($f->wali)->get("/wali/santri/{$f->o1->id}/rapor/{$f->year->id}/1")->assertNotFound();
    $this->actingAs($f->other)->get($this->url)->assertNotFound();
    expect(bayarHtmlText($this->actingAs($f->wali)->get("/wali/santri/{$f->o1->id}")->getContent()))->not->toContain('Aqidah');
});

it('AC-10: answers 404 for a semester without scores, another year, or an invalid semester', function () {
    $f = $this->f;
    $this->actingAs($f->wali);

    $this->get("/wali/santri/{$f->s1->id}/rapor/{$f->year->id}/2")->assertNotFound();
    $this->get("/wali/santri/{$f->s1->id}/rapor/{$f->year->id}/3")->assertNotFound();
    $this->get("/wali/santri/{$f->s2->id}/rapor/{$f->year->id}/1")->assertNotFound();
    $this->get("/wali/santri/{$f->s1->id}/rapor/999999/1")->assertNotFound();
});

it('AC-10: keeps the admin print route closed to the wali', function () {
    $f = $this->f;

    expect($this->actingAs($f->wali)->get("/admin/akademik/rapor/{$f->s1->id}/{$f->year->id}/1")->status())->toBeIn([302, 403, 404]);
    $this->get("/admin/akademik/rapor/{$f->s1->id}/{$f->year->id}/1")->assertDontSee('Aqidah');
});

it('AC-10: redirects a guest to the portal login', function () {
    $this->get($this->url)->assertRedirect();
});

it('AC-11: hides the section and answers 404 when akademik is off, keeping the data', function () {
    $f = $this->f;
    Setting::set('modules.akademik.enabled', false);

    $page = $this->actingAs($f->wali)->get("/wali/santri/{$f->s1->id}");

    expect($page->status())->toBeIn([200, 404]);

    if ($page->status() === 200) {
        expect(bayarHtmlText($page->getContent()))->not->toContain(__('pondok.diniyah.portal.title'));
    }

    $this->get($this->url)->assertNotFound();
    expect(DB::table('diniyah_grades')->count())->toBe(3);
});

it('AC-11: answers 404 for the report when portal-wali is off', function () {
    $f = $this->f;
    Setting::set('modules.portal-wali.enabled', false);

    $this->actingAs($f->wali)->get($this->url)->assertNotFound();
});
