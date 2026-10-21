<?php

namespace App\Console\Commands;

use App\Actions\Keuangan\PruneFinanceExports;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Menjalankan `PruneFinanceExports` dari scheduler (setiap jam) atau manual.
 */
#[Signature('keuangan:prune-finance-exports')]
#[Description('Menghapus berkas CSV laporan keuangan yang berumur lebih dari 24 jam')]
class PruneFinanceExportsCommand extends Command
{
    public function handle(PruneFinanceExports $prune): int
    {
        $this->components->info(__('pondok.finance_report.prune_done', ['count' => $prune->handle()]));

        return self::SUCCESS;
    }
}
