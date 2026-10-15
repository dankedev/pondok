<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->f = dokumenFixture();
    $this->kk = dokumenCreate($this->f->s1, 'KK', ['content' => 'isi-kartu-keluarga', 'original_name' => 'kk-ahmad-satu.pdf']);
    $this->ijazah = dokumenCreate($this->f->s1, 'IJAZAH', ['content' => 'isi-ijazah']);
});

it('AC-30: downloads a document as an attachment', function () {
    $response = $this->actingAs($this->f->admin)->get("/admin/documents/{$this->kk->id}/download")->assertOk();

    expect($response->streamedContent())->toBe('isi-kartu-keluarga')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain("kk-{$this->kk->id}.pdf")
        ->not->toContain('kk-ahmad-satu')->not->toContain('Ahmad')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
});

it('AC-30: names the download after the lowercase type code and the stored extension', function () {
    $response = $this->actingAs($this->f->admin)->get("/admin/documents/{$this->ijazah->id}/download")->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain("ijazah-{$this->ijazah->id}.pdf");
});

it('AC-31: guards document downloads', function (string $case) {
    $f = $this->f;

    match ($case) {
        'guest' => $this->get("/admin/documents/{$this->kk->id}/download")->assertRedirect(route('filament.admin.auth.login')),
        'kesantrian confidential' => $this->actingAs(keuanganUser('kesantrian'))->get("/admin/documents/{$this->kk->id}/download")->assertForbidden(),
        'kesantrian plain' => $this->actingAs(keuanganUser('kesantrian'))->get("/admin/documents/{$this->ijazah->id}/download")->assertOk(),
        'bendahara' => $this->actingAs(keuanganUser('bendahara'))->get("/admin/documents/{$this->kk->id}/download")->assertForbidden(),
        'admin-psb' => $this->actingAs(keuanganUser('admin-psb'))->get("/admin/documents/{$this->ijazah->id}/download")->assertForbidden(),
        'other unit' => $this->actingAs(keuanganUser('admin-sekolah', ['SLTP']))->get("/admin/documents/{$this->kk->id}/download")->assertNotFound(),
        'own unit' => $this->actingAs(keuanganUser('admin-sekolah', ['SD']))->get("/admin/documents/{$this->kk->id}/download")->assertOk(),
        'unknown id' => $this->actingAs($f->admin)->get('/admin/documents/999999/download')->assertNotFound(),
        'non numeric id' => $this->actingAs($f->admin)->get('/admin/documents/abc/download')->assertNotFound(),
        'missing file' => (function () use ($f): void {
            Storage::disk('private')->delete($this->kk->file_path);
            test()->actingAs($f->admin)->get("/admin/documents/{$this->kk->id}/download")->assertNotFound();
        })(),
        'module off' => (function () use ($f): void {
            Setting::set('modules.dokumen.enabled', false);
            test()->actingAs($f->admin)->get("/admin/documents/{$this->kk->id}/download")->assertForbidden();
        })(),
        'module off with unknown id' => (function () use ($f): void {
            Setting::set('modules.dokumen.enabled', false);
            test()->actingAs($f->admin)->get('/admin/documents/999999/download')->assertForbidden();
        })(),
        'module off for super-admin' => (function (): void {
            Setting::set('modules.dokumen.enabled', false);
            test()->actingAs(keuanganUser('super-admin'))->get("/admin/documents/{$this->kk->id}/download")->assertForbidden();
        })(),
    };
})->with([
    'guest', 'kesantrian confidential', 'kesantrian plain', 'bendahara', 'admin-psb', 'other unit', 'own unit',
    'unknown id', 'non numeric id', 'missing file', 'module off', 'module off with unknown id', 'module off for super-admin',
]);

it('AC-31: keeps guardian and staff documents from kesantrian', function () {
    $type = dokumenType(['code' => 'WALIDOK', 'owner' => 'wali']);
    $document = dokumenCreate($this->f->g1, $type);

    $this->actingAs(keuanganUser('kesantrian'))->get("/admin/documents/{$document->id}/download")->assertForbidden();
    $this->actingAs($this->f->admin)->get("/admin/documents/{$document->id}/download")->assertOk();
});

it('AC-31: never serves a file that only exists on the public disk', function () {
    Storage::disk('private')->delete($this->kk->file_path);
    Storage::disk('public')->put($this->kk->file_path, 'rahasia');

    $this->actingAs($this->f->admin)->get("/admin/documents/{$this->kk->id}/download")->assertNotFound();
    expect($this->get('/storage/'.$this->kk->file_path)->status())->toBeIn([403, 404]);
});

it('AC-31: answers 404 for a document without a file path', function () {
    $this->kk->forceFill(['file_path' => ''])->save();

    $this->actingAs($this->f->admin)->get("/admin/documents/{$this->kk->id}/download")->assertNotFound();
});

it('AC-31: still serves the file of a replaced version from the history', function () {
    $new = dokumenCreate($this->f->s1, 'KK');
    $this->kk->forceFill(['replaced_by_id' => $new->id])->save();

    $response = $this->actingAs($this->f->admin)->get("/admin/documents/{$this->kk->id}/download")->assertOk();

    expect($response->streamedContent())->toBe('isi-kartu-keluarga');
});
