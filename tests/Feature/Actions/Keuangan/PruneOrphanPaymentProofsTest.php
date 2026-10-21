<?php

use App\Actions\Keuangan\PruneOrphanPaymentProofs;
use App\Models\Payment;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');
});

/**
 * Simpan berkas di disk private dan atur umurnya (jam).
 */
function proofFile(string $path, int $ageHours): string
{
    Storage::disk('private')->put($path, 'bukti');
    touch(Storage::disk('private')->path($path), now()->subHours($ageHours)->getTimestamp());

    return $path;
}

it('AC-11: deletes old proofs without a payment and keeps referenced or recent ones', function () {
    $orphan = proofFile('payment-proofs/2026/10/yatim.jpg', 25);
    $received = proofFile('payment-proofs/2026/10/diterima.jpg', 48);
    $cancelled = proofFile('payment-proofs/2026/10/batal.jpg', 48);
    $recent = proofFile('payment-proofs/2026/10/baru.jpg', 23);
    Payment::factory()->transfer()->create(['proof_path' => $received]);
    Payment::factory()->transfer()->cancelled()->create(['proof_path' => $cancelled]);
    Log::spy();

    $deleted = app(PruneOrphanPaymentProofs::class)->handle();

    expect($deleted)->toBe(1);
    Storage::disk('private')->assertMissing($orphan);
    Storage::disk('private')->assertExists([$received, $cancelled, $recent]);
    Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => ($context['deleted'] ?? null) === 1)->once();
});

it('AC-12: touches only the payment-proofs directory and ignores dotfiles', function () {
    $other = proofFile('documents/2026/ktp.pdf', 100);
    $dotfile = proofFile('payment-proofs/.gitkeep', 100);

    expect(app(PruneOrphanPaymentProofs::class)->handle())->toBe(0);
    Storage::disk('private')->assertExists([$other, $dotfile]);
});

it('AC-12: returns zero when the directory does not exist', function () {
    expect(app(PruneOrphanPaymentProofs::class)->handle())->toBe(0);
});

it('AC-12: counts payments of other units as references even for a unit-scoped user', function () {
    $this->seed(RolePermissionSeeder::class);
    $f = tagihanFixture();
    $slta = tagihanInvoice(tagihanStudent($f, attributes: ['unit_id' => $f->slta->id], cohort: $f->cohortSlta), $f->year);
    $proof = proofFile('payment-proofs/2026/10/slta.jpg', 48);
    Payment::factory()->transfer()->create(['invoice_id' => $slta->id, 'proof_path' => $proof]);
    $this->actingAs(keuanganUser('bendahara', ['SLTP']));

    expect(app(PruneOrphanPaymentProofs::class)->handle())->toBe(0);
    Storage::disk('private')->assertExists($proof);
});
