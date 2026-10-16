<?php

use App\Models\Setting;

beforeEach(function () {
    $this->f = prestasiFixture();
});

it('AC-29: shows prestasi pages per role (:role)', function (string $role, bool $manages) {
    $user = keuanganUser($role);

    $this->actingAs($user)->get('/admin/achievements')->assertOk();
    $this->actingAs($user)->get('/admin/achievement-recap')->assertOk();
    $this->actingAs($user)->get('/admin/achievements/create')->assertStatus($manages ? 200 : 403);

    $navigation = prestasiNavigationText($user);

    expect($navigation)->toContain('Prestasi')->toContain('Rekap prestasi');
})->with([
    'kesantrian' => ['kesantrian', true],
    'admin-sekolah' => ['admin-sekolah', false],
]);

it('AC-29: forbids prestasi pages for other roles (:role)', function (string $role) {
    $user = keuanganUser($role);

    $this->actingAs($user)->get('/admin/achievements')->assertForbidden();
    $this->actingAs($user)->get('/admin/achievement-recap')->assertForbidden();
    $this->actingAs($user)->get('/admin/achievements/create')->assertForbidden();

    expect(prestasiNavigationText($user))->not->toContain('Rekap prestasi');
})->with(['admin-konten', 'bendahara', 'admin-psb', 'musyrif']);

it('AC-29: opens the pages for super-admin', function () {
    $this->actingAs($this->f->admin)->get('/admin/achievements')->assertOk();
    $this->actingAs($this->f->admin)->get('/admin/achievements/create')->assertOk();
    $this->actingAs($this->f->admin)->get('/admin/achievement-recap')->assertOk();
});

it('AC-29: hides prestasi when the module is off (:role)', function (string $role) {
    Setting::set('modules.prestasi.enabled', false);
    $user = keuanganUser($role);

    $this->actingAs($user)->get('/admin/achievements')->assertForbidden();
    $this->actingAs($user)->get('/admin/achievements/create')->assertForbidden();
    $this->actingAs($user)->get('/admin/achievement-recap')->assertForbidden();

    expect(prestasiNavigationText($user))->not->toContain('Rekap prestasi')->not->toContain('Prestasi');
})->with(['kesantrian', 'super-admin']);

it('AC-29: hides achievements outside the unit scope', function () {
    $f = $this->f;
    $p3 = prestasiCreate($f, [$f->s1]);
    $p1 = prestasiCreate($f, [$f->s2]);

    $this->actingAs($f->a2)->get("/admin/achievements/{$p3->id}/edit")->assertNotFound();
    $this->actingAs($f->k2)->get("/admin/achievements/{$p3->id}/edit")->assertNotFound();
    $this->actingAs($f->k2)->get("/admin/achievements/{$p1->id}/edit")->assertOk();
    $this->actingAs($f->a2)->get("/admin/achievements/{$p1->id}/edit")->assertForbidden();
});

it('AC-29: keeps the document module independent from the prestasi pages', function () {
    Setting::set('modules.dokumen.enabled', false);
    $f = $this->f;
    $p1 = prestasiCreate($f, [$f->s2]);

    $this->actingAs($f->k)->get("/admin/achievements/{$p1->id}/edit")->assertOk();
    $this->actingAs($f->k)->get('/admin/achievements')->assertOk();
});
