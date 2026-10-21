<?php

namespace App\Console\Commands;

use App\Actions\Dokumen\RedactExpiredLetterSnapshots;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Menjalankan `RedactExpiredLetterSnapshots` dari scheduler (setiap hari) atau manual.
 * Tidak terikat saklar modul Dokumen: kewajiban masa simpan tetap berlaku.
 */
#[Signature('pondok:redact-letter-snapshots')]
#[Description('Menghapus data pribadi snapshot surat keluar yang melewati masa simpan')]
class RedactLetterSnapshotsCommand extends Command
{
    public function handle(RedactExpiredLetterSnapshots $redact): int
    {
        $this->components->info(__('pondok.data_retention.redact_done', ['count' => $redact->handle()]));

        return self::SUCCESS;
    }
}
