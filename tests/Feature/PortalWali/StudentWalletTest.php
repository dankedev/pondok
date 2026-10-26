<?php

use App\Actions\Keuangan\VoidWalletTransaction;
use App\Models\Setting;
use App\Models\WalletTransaction;

beforeEach(function () {
    $this->f = waliBoot();
    $this->staff = keuanganUser('bendahara');
});

it('AC-13: shows the child\'s balance and mutations with voided rows labelled', function () {
    walletRecord($this->f->s1, $this->staff, 'setor', 80000, ['note' => 'Titipan awal bulan']);
    walletRecord($this->f->s1, $this->staff, 'tarik', 15000, ['note' => 'Beli buku']);
    $voided = walletRecord($this->f->s1, $this->staff, 'setor', 4321, ['note' => 'Salah nominal']);
    app(VoidWalletTransaction::class)->handle($voided, $this->staff, 'Salah');

    $text = waliText($this->f->wali, "/wali/santri/{$this->f->s1->id}");

    expect($text)->toContain(__('pondok.wallet.portal.title'))
        ->toContain('Rp 65.000')
        ->toContain('Rp 80.000')
        ->toContain('Rp 15.000')
        ->toContain('Titipan awal bulan')
        ->toContain('Salah nominal')
        ->toContain(__('pondok.wallet.status.batal'));
});

it('AC-13: shows only the 20 latest mutations', function () {
    foreach (range(1, 22) as $i) {
        walletRecord($this->f->s1, $this->staff, 'setor', 1000 + $i, ['note' => sprintf('mutasi-%02d', $i)]);
    }

    $text = waliText($this->f->wali, "/wali/santri/{$this->f->s1->id}");

    expect($text)->toContain('mutasi-22')->toContain('mutasi-03')->not->toContain('mutasi-02')->not->toContain('mutasi-01');
});

it('AC-13: shows a zero balance for a child without transactions', function () {
    $text = waliText($this->f->wali, "/wali/santri/{$this->f->s2->id}");

    expect($text)->toContain(__('pondok.wallet.portal.title'))->toContain('Rp 0');
});

it('AC-13: does not mix siblings\' or other guardians\' wallet data', function () {
    walletRecord($this->f->s2, $this->staff, 'setor', 77777, ['note' => 'Milik adik']);
    walletRecord($this->f->o1, $this->staff, 'setor', 88888, ['note' => 'Milik orang lain']);

    $text = waliText($this->f->wali, "/wali/santri/{$this->f->s1->id}");

    expect($text)->not->toContain('Rp 77.777')->not->toContain('Rp 88.888')->not->toContain('Milik adik')->not->toContain('Milik orang lain');

    $this->actingAs($this->f->wali)->get("/wali/santri/{$this->f->o1->id}")->assertNotFound();
});

it('AC-13: escapes the note of a mutation', function () {
    walletRecord($this->f->s1, $this->staff, 'setor', 5000, ['note' => '<script>alert(1)</script>']);

    $html = $this->actingAs($this->f->wali)->get("/wali/santri/{$this->f->s1->id}")->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>');
});

it('AC-13: hides the section when the keuangan module is off but keeps the page and data', function () {
    walletRecord($this->f->s1, $this->staff, 'setor', 80000, ['note' => 'Titipan awal bulan']);
    Setting::set('modules.keuangan.enabled', false);

    $text = waliText($this->f->wali, "/wali/santri/{$this->f->s1->id}");

    expect($text)->not->toContain(__('pondok.wallet.portal.title'))->not->toContain('Titipan awal bulan')
        ->and(WalletTransaction::withoutUnitScope()->count())->toBe(1);
});

it('AC-13: shows the wallet of an alumni child', function () {
    $this->f->s1->forceFill(['status' => 'lulus'])->save();
    walletRecord($this->f->s1, $this->staff, 'setor', 12000);

    expect(waliText($this->f->wali, "/wali/santri/{$this->f->s1->id}"))->toContain('Rp 12.000');
});

it('AC-13: has Indonesian texts for the wallet', function () {
    expectTranslatedKeys([
        'pondok.wallet.portal.title', 'pondok.wallet.status.batal', 'pondok.wallet.status.berhasil',
        'pondok.wallet.types.setor', 'pondok.wallet.types.tarik',
        'pondok.wallet.types.koreksi_tambah', 'pondok.wallet.types.koreksi_kurang',
    ]);
});
