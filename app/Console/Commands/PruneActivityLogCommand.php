<?php

namespace App\Console\Commands;

use App\Actions\Core\PruneActivityLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Menjalankan `PruneActivityLog` dari scheduler (setiap hari) atau manual.
 * Aman dijalankan saat masa simpan kosong (tidak ada yang dihapus).
 */
#[Signature('pondok:prune-activity-log')]
#[Description('Menghapus log aktivitas akademik dan konten yang melewati masa simpan')]
class PruneActivityLogCommand extends Command
{
    public function handle(PruneActivityLog $prune): int
    {
        $this->components->info(__('pondok.data_retention.activity_log_pruned', ['count' => $prune->handle()]));

        return self::SUCCESS;
    }
}
