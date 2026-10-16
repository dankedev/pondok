<?php

namespace App\Actions\Core;

/**
 * Menulis waktu terakhir scheduler berjalan (unix timestamp) ke berkas di
 * storage. Berkas, bukan cache/DB: tidak hilang oleh `optimize:clear` dan
 * tetap bisa dibaca saat database bermasalah.
 */
class RecordSchedulerHeartbeat
{
    public const PATH = 'framework/pondok-scheduler-heartbeat';

    public function handle(): void
    {
        file_put_contents(storage_path(self::PATH), (string) now()->timestamp);
    }
}
