<?php

use App\Actions\Keuangan\PruneOrphanPaymentProofs;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

it('VERIFY: a failed delete logs a warning and continues with the other files', function () {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('allFiles')->andReturn(['payment-proofs/a.jpg', 'payment-proofs/b.jpg']);
    $disk->shouldReceive('lastModified')->andReturn(now()->subDays(3)->getTimestamp());
    $disk->shouldReceive('delete')->with('payment-proofs/a.jpg')->once()->andReturn(false);
    $disk->shouldReceive('delete')->with('payment-proofs/b.jpg')->once()->andReturn(true);
    Storage::shouldReceive('disk')->with('private')->andReturn($disk);
    Log::spy();

    expect(app(PruneOrphanPaymentProofs::class)->handle())->toBe(1);
    Log::shouldHaveReceived('warning')->once();
});

it('VERIFY: a file exactly 24 hours old is kept, one second older is deleted', function () {
    Storage::fake('private');
    $this->travelTo('2026-10-15 09:00:00');
    foreach (['batas' => 24 * 3600, 'lewat' => 24 * 3600 + 1] as $name => $age) {
        Storage::disk('private')->put("payment-proofs/{$name}.jpg", 'x');
        touch(Storage::disk('private')->path("payment-proofs/{$name}.jpg"), now()->getTimestamp() - $age);
    }

    app(PruneOrphanPaymentProofs::class)->handle();

    Storage::disk('private')->assertExists('payment-proofs/batas.jpg');
    Storage::disk('private')->assertMissing('payment-proofs/lewat.jpg');
});

it('VERIFY: a path that merely shares a prefix with payment-proofs is untouched', function () {
    Storage::fake('private');
    Storage::disk('private')->put('payment-proofs-archive/x.jpg', 'x');
    touch(Storage::disk('private')->path('payment-proofs-archive/x.jpg'), now()->subDays(9)->getTimestamp());

    expect(app(PruneOrphanPaymentProofs::class)->handle())->toBe(0);
    Storage::disk('private')->assertExists('payment-proofs-archive/x.jpg');
});
