<?php

namespace App\Console\Commands;

use App\Actions\Keuangan\PruneOrphanPaymentProofs;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Menjalankan `PruneOrphanPaymentProofs` dari scheduler (harian) atau manual.
 */
#[Signature('keuangan:prune-payment-proofs')]
#[Description('Menghapus berkas bukti transfer yang tidak dirujuk pembayaran mana pun')]
class PrunePaymentProofsCommand extends Command
{
    public function handle(PruneOrphanPaymentProofs $prune): int
    {
        $this->components->info(__('pondok.payment.proofs_pruned', ['count' => $prune->handle()]));

        return self::SUCCESS;
    }
}
