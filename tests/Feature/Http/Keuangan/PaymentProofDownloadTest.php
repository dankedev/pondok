<?php

use App\Models\Payment;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed([RolePermissionSeeder::class, SettingSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Jakarta'));
    Storage::fake('private');
    Storage::fake('public');
    $this->f = tagihanFixture();
    $this->officer = keuanganUser('bendahara');
});

/**
 * Pembayaran transfer milik santri fixture dengan berkas bukti di disk private.
 */
function proofPayment(object $f, string $path = 'payment-proofs/2026/10/abc123.jpg', ?string $content = 'isi-bukti-transfer'): Payment
{
    $invoice = bayarInvoice($f);
    $payment = bayarRecord($invoice, 500000, ['method' => 'transfer']);
    $payment->forceFill(['proof_path' => $path])->save();

    if ($content !== null) {
        Storage::disk('private')->put($path, $content);
    }

    return $payment;
}

it('AC-44: downloads proof only for authorised users', function () {
    $payment = proofPayment($this->f);

    $response = $this->actingAs($this->officer)->get("/admin/payment-proofs/{$payment->id}")->assertOk();

    expect($response->streamedContent())->toBe('isi-bukti-transfer')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('bukti-transfer-KW-202610-00001.jpg')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('AC-44: names the download after the stored extension', function () {
    $payment = proofPayment($this->f, 'payment-proofs/2026/10/xyz.pdf');

    $response = $this->actingAs($this->officer)->get("/admin/payment-proofs/{$payment->id}")->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('bukti-transfer-KW-202610-00001.pdf');
});

it('AC-44: rejects guests and roles without view permission', function (string $case) {
    $payment = proofPayment($this->f);

    match ($case) {
        'guest' => $this->get("/admin/payment-proofs/{$payment->id}")->assertRedirect(route('filament.admin.auth.login')),
        'admin-sekolah' => $this->actingAs(keuanganUser('admin-sekolah'))->get("/admin/payment-proofs/{$payment->id}")->assertForbidden(),
        'kesantrian' => $this->actingAs(keuanganUser('kesantrian'))->get("/admin/payment-proofs/{$payment->id}")->assertForbidden(),
        'unknown id' => $this->actingAs($this->officer)->get('/admin/payment-proofs/999999')->assertNotFound(),
    };
})->with(['guest', 'admin-sekolah', 'kesantrian', 'unknown id']);

it('AC-44: answers 404 without a proof or when the file is gone', function () {
    $noProof = bayarRecord(bayarInvoice($this->f), 100000);
    $missing = proofPayment($this->f, 'payment-proofs/2026/10/hilang.jpg', content: null);

    $this->actingAs($this->officer)->get("/admin/payment-proofs/{$noProof->id}")->assertNotFound();
    $this->actingAs($this->officer)->get("/admin/payment-proofs/{$missing->id}")->assertNotFound();
});

it('AC-44: never serves a file from the public disk', function () {
    $payment = proofPayment($this->f, 'payment-proofs/2026/10/publik.jpg', content: null);
    Storage::disk('public')->put('payment-proofs/2026/10/publik.jpg', 'rahasia');

    $this->actingAs($this->officer)->get("/admin/payment-proofs/{$payment->id}")->assertNotFound();
});

it('AC-29: hides proofs of other units behind a 404', function () {
    $f = $this->f;
    $slta = tagihanInvoice(tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta), $f->year, ['billing_group' => 'X']);
    $other = Payment::factory()->transfer()->create(['invoice_id' => $slta->id, 'proof_path' => 'payment-proofs/2026/10/slta.jpg']);
    Storage::disk('private')->put('payment-proofs/2026/10/slta.jpg', 'bukti-slta');
    $own = proofPayment($f);
    $scoped = keuanganUser('bendahara', ['SLTP']);

    $this->actingAs($scoped)->get("/admin/payment-proofs/{$other->id}")->assertNotFound();
    $this->actingAs($scoped)->get("/admin/payment-proofs/{$own->id}")->assertOk();
});
