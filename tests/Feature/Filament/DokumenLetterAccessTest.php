<?php

use App\Models\Setting;

beforeEach(function () {
    $this->f = suratFixture();
});

it('AC-33: shows issued letters only to permitted roles', function (string $role, int $status, bool $seesMenu) {
    $user = suratUser($role);

    $this->actingAs($user)->get('/admin/issued-letters')->assertStatus($status);

    expect(str_contains(dokumenNavigationText($user), 'Surat keluar'))->toBe($seesMenu);
})->with([
    'admin-sekolah' => ['admin-sekolah', 200, true],
    'super-admin' => ['super-admin', 200, true],
    'bendahara' => ['bendahara', 403, false],
    'kesantrian' => ['kesantrian', 403, false],
    'admin-psb' => ['admin-psb', 403, false],
    'admin-konten' => ['admin-konten', 403, false],
    'musyrif' => ['musyrif', 403, false],
    'ustadz-tahfidz' => ['ustadz-tahfidz', 403, false],
    'petugas-pos' => ['petugas-pos', 403, false],
]);

it('AC-33: puts the menu in the Dokumen group', function () {
    $navigation = dokumenNavigationText(suratUser('admin-sekolah'));

    expect(preg_match('/\bDokumen\b.*Surat keluar/u', $navigation))->toBe(1);
});

it('AC-33: hides issued letters when the module is off', function (string $role) {
    Setting::set('modules.dokumen.enabled', false);
    $user = suratUser($role);

    $this->actingAs($user)->get('/admin/issued-letters')->assertForbidden();

    expect(str_contains(dokumenNavigationText($user), 'Surat keluar'))->toBeFalse();
})->with(['admin-sekolah', 'super-admin']);

it('AC-33: keeps the guest on the login page', function () {
    $this->get('/admin/issued-letters')->assertRedirect(route('filament.admin.auth.login'));
});

it('AC-33: has no create or edit page for issued letters', function () {
    $letter = suratIssue($this->f);
    $this->actingAs($this->f->admin);

    $this->get('/admin/issued-letters/create')->assertNotFound();
    $this->get("/admin/issued-letters/{$letter->id}/edit")->assertNotFound();
});
