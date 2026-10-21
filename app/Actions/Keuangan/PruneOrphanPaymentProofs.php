<?php

namespace App\Actions\Keuangan;

use App\Models\Payment;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Menghapus berkas bukti transfer yatim: berkas di `payment-proofs` yang
 * berumur lebih dari `GRACE_HOURS` dan tidak dirujuk `payments.proof_path`
 * mana pun (semua status dan jenjang, termasuk `batal`). Berkas yatim muncul
 * bila unggahan selesai tetapi pembayaran tidak jadi dicatat (validasi gagal, modal ditutup).
 *
 * Masa tenggang melindungi unggahan yang pembayarannya belum disimpan.
 * Berkas titik (`.gitkeep`) diabaikan. Satu berkas yang gagal dihapus dicatat
 * lalu dilewati.
 */
class PruneOrphanPaymentProofs
{
    public const GRACE_HOURS = 24;

    public const CHUNK_SIZE = 500;

    /**
     * @return int jumlah berkas yang dihapus
     */
    public function handle(?CarbonInterface $now = null): int
    {
        $disk = Storage::disk(Payment::PROOF_DISK);
        $cutoff = ($now ?? now())->copy()->subHours(self::GRACE_HOURS)->getTimestamp();

        $candidates = collect($disk->allFiles(Payment::PROOF_DIRECTORY))
            ->reject(fn (string $path): bool => str_starts_with(basename($path), '.'))
            ->filter(fn (string $path): bool => $disk->lastModified($path) < $cutoff)
            ->values();

        $deleted = 0;

        foreach ($candidates->chunk(self::CHUNK_SIZE) as $chunk) {
            $referenced = Payment::withoutGlobalScopes()
                ->whereIn('proof_path', $chunk->all())
                ->pluck('proof_path')
                ->flip();

            foreach ($chunk as $path) {
                if ($referenced->has($path)) {
                    continue;
                }

                if (! $disk->delete($path)) {
                    Log::warning('Bukti transfer yatim gagal dihapus', ['path' => $path]);

                    continue;
                }

                $deleted++;
            }
        }

        Log::info('Pembersihan bukti transfer yatim selesai', ['deleted' => $deleted, 'checked' => $candidates->count()]);

        return $deleted;
    }
}
