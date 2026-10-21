<?php

namespace App\Console\Commands;

use App\Actions\Core\CreateBackup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Throwable;

/**
 * Menjalankan `CreateBackup` dari scheduler (bila `backup.enabled`) atau manual.
 */
#[Signature('pondok:backup')]
#[Description('Membuat arsip backup database dan berkas di storage/app/private/backups')]
class BackupCommand extends Command
{
    public function handle(CreateBackup $backup): int
    {
        try {
            $path = $backup->handle();
        } catch (Throwable $e) {
            Log::error('pondok:backup failed', ['exception' => $e]);
            $this->components->error(__('pondok.backup.failed', ['error' => $e->getMessage()]));

            return self::FAILURE;
        }

        $this->components->info(__('pondok.backup.done', [
            'file' => $path,
            'size' => Number::fileSize(Storage::disk(CreateBackup::DISK)->size($path)),
        ]));

        return self::SUCCESS;
    }
}
