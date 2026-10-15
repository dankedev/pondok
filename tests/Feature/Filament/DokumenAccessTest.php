<?php

use App\Models\Setting;

beforeEach(function () {
    $this->f = dokumenFixture();
});

it('AC-32: shows dokumen pages per role', function (string $role, int $documents, int $types, bool $seesDocuments, bool $seesTypes) {
    $user = keuanganUser($role);

    $this->actingAs($user)->get('/admin/documents')->assertStatus($documents);
    $this->actingAs($user)->get('/admin/document-types')->assertStatus($types);

    $navigation = dokumenNavigationText($user);

    expect(preg_match('/\bDokumen\b/u', $navigation) === 1)->toBe($seesDocuments)
        ->and(str_contains($navigation, 'Jenis dokumen'))->toBe($seesTypes);
})->with([
    'admin-sekolah' => ['admin-sekolah', 200, 200, true, true],
    'kesantrian' => ['kesantrian', 200, 403, true, false],
    'bendahara' => ['bendahara', 403, 403, false, false],
    'admin-psb' => ['admin-psb', 403, 403, false, false],
    'musyrif' => ['musyrif', 403, 403, false, false],
    'admin-konten' => ['admin-konten', 403, 403, false, false],
]);

it('AC-32: lets super-admin open both pages', function () {
    $user = keuanganUser('super-admin');

    $this->actingAs($user)->get('/admin/documents')->assertOk();
    $this->actingAs($user)->get('/admin/document-types')->assertOk();
});

it('AC-32: names the menu group after the module', function () {
    expect(__('pondok.navigation.dokumen'))->toBe('Dokumen');

    expect(dokumenNavigationText(keuanganUser('admin-sekolah')))->toContain('Dokumen');
});

it('AC-32: hides dokumen when the module is off', function (string $role) {
    Setting::set('modules.dokumen.enabled', false);
    $user = keuanganUser($role);

    $this->actingAs($user)->get('/admin/documents')->assertForbidden();
    $this->actingAs($user)->get('/admin/document-types')->assertForbidden();

    $navigation = dokumenNavigationText($user);

    expect(preg_match('/\bDokumen\b/u', $navigation))->toBe(0)
        ->and($navigation)->not->toContain('Jenis dokumen');
})->with(['admin-sekolah', 'super-admin']);

it('AC-32: keeps the other modules reachable when dokumen is off', function () {
    Setting::set('modules.dokumen.enabled', false);

    $this->actingAs(keuanganUser('admin-sekolah'))->get('/admin/students')->assertOk();
});
