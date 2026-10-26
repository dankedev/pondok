<?php

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/*
| Kontrak: GET `/admin/akademik/rapor/{student}/{year}/{semester}` (panel admin, login, saklar Akademik),
| controller `App\Http\Controllers\Akademik\PrintDiniyahReport`. Santri tanpa enrollment di tahun itu atau
| tanpa satu pun nilai = 404. Predikat tampil lewat `pondok.diniyah.predicates.<value>`.
*/

beforeEach(function () {
    $this->f = diniyahFixture();
    $f = $this->f;
    diniyahGrade($f->s1, $f->year, 1, $f->aqidah, 90);
    diniyahGrade($f->s1, $f->year, 1, $f->fiqih, 81);
    diniyahGrade($f->s1, $f->year, 1, $f->nahwu, 70);
    diniyahNote($f->s1, $f->year, 1, 'Rajin berjamaah');
    $this->url = "/admin/akademik/rapor/{$f->s1->id}/{$f->year->id}/1";
});

it('AC-9: prints name, class, subjects, scores, predicates, average, note and homeroom teacher', function () {
    $f = $this->f;
    $response = $this->actingAs($f->admin)->get($this->url)->assertOk();
    $text = bayarHtmlText($response->getContent());

    expect($response->headers->get('Content-Type'))->toContain('text/html')
        ->and($text)->toContain('Santri Satu')->toContain($f->s1->nis)->toContain('7A')->toContain('2026/2027')
        ->toContain('Aqidah')->toContain('Fiqih')->toContain('Nahwu')
        ->toContain('90')->toContain('81')->toContain('70')
        ->toContain(__('pondok.diniyah.predicates.mumtaz'))
        ->toContain(__('pondok.diniyah.predicates.jayyid_jiddan'))
        ->toContain(__('pondok.diniyah.predicates.jayyid'))
        ->toContain('80,3')
        ->toContain('Rajin berjamaah')
        ->toContain($f->homeroomStaff->name)
        ->toContain(__('pondok.diniyah.report.title'))
        ->not->toContain('Sharaf')->not->toContain('Hadits')
        ->not->toContain('Santri Dua')
        ->not->toMatch('/pondok\.diniyah\b/')
        ->not->toMatch('/peringkat|rangking|ranking/i');
});

it('AC-9: escapes the homeroom note in the printed page', function () {
    $f = $this->f;
    DB::table('diniyah_report_notes')->update(['note' => '<script>alert(1)</script>']);

    $html = $this->actingAs($f->admin)->get($this->url)->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>')->toContain('&lt;script&gt;');
});

it('AC-9: lets kesantrian, the homeroom teacher and unit-scoped staff of the unit print', function (string $account) {
    $f = $this->f;
    $this->actingAs($f->{$account})->get($this->url)->assertOk();
})->with(['kes', 'homeroom', 'adminSltp', 'kesSltp']);

it('AC-9: prints a report of a past academic year that is not active', function () {
    $f = $this->f;
    diniyahGrade($f->old, $f->prev, 2, $f->aqidah, 66);

    $this->actingAs($f->admin)->get("/admin/akademik/rapor/{$f->old->id}/{$f->prev->id}/2")->assertOk();
    $this->actingAs($f->homeroom)->get("/admin/akademik/rapor/{$f->old->id}/{$f->prev->id}/2")->assertOk();
});

it('AC-9: refuses :account and shows nothing', function (string $account) {
    $f = $this->f;
    $user = property_exists($f, $account) ? $f->{$account} : asramaUser($account);

    expect($this->actingAs($user)->get($this->url)->status())->toBeIn([403, 404]);
})->with(['idle', 'noStaff', 'musyrif', 'bendahara', 'wali', 'admin-konten']);

it('AC-9: refuses a student of another unit for a user scoped to one unit and for another homeroom', function () {
    $f = $this->f;
    diniyahGrade($f->o10, $f->year, 1, $f->hadits, 80);
    diniyahGrade($f->o7b, $f->year, 1, $f->aqidah, 80);

    expect($this->actingAs($f->adminSltp)->get("/admin/akademik/rapor/{$f->o10->id}/{$f->year->id}/1")->status())->toBeIn([403, 404])
        ->and($this->actingAs($f->kesSltp)->get("/admin/akademik/rapor/{$f->o10->id}/{$f->year->id}/1")->status())->toBeIn([403, 404])
        ->and($this->actingAs($f->homeroom)->get("/admin/akademik/rapor/{$f->o7b->id}/{$f->year->id}/1")->status())->toBeIn([403, 404])
        ->and($this->actingAs($f->admin)->get("/admin/akademik/rapor/{$f->o10->id}/{$f->year->id}/1")->status())->toBe(200);
});

it('AC-9: redirects a guest to the login page', function () {
    $this->get($this->url)->assertRedirect(route('filament.admin.auth.login'));
});

it('AC-9: answers 404 without enrollment in that year, without any score, or with an invalid semester', function () {
    $f = $this->f;
    $this->actingAs($f->admin);

    $this->get("/admin/akademik/rapor/{$f->s1->id}/{$f->prev->id}/1")->assertNotFound();
    $this->get("/admin/akademik/rapor/{$f->s2->id}/{$f->year->id}/1")->assertNotFound();
    $this->get("/admin/akademik/rapor/{$f->s1->id}/{$f->year->id}/2")->assertNotFound();
    $this->get("/admin/akademik/rapor/{$f->s1->id}/{$f->year->id}/3")->assertNotFound();
    $this->get("/admin/akademik/rapor/{$f->s1->id}/{$f->year->id}/abc")->assertNotFound();
    $this->get("/admin/akademik/rapor/999999/{$f->year->id}/1")->assertNotFound();
});

it('AC-11: closes the print route when akademik is off and keeps the data', function () {
    $f = $this->f;
    $this->actingAs($f->admin)->get($this->url)->assertOk();

    Setting::set('modules.akademik.enabled', false);

    expect($this->get($this->url)->status())->toBeIn([403, 404])
        ->and(DB::table('diniyah_grades')->count())->toBe(3);
});
