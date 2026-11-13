<?php

namespace App\Console\Commands;

use App\Actions\Konten\ExportPortalTheme;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Untuk penerbit tema resmi (F111, AC-11): mengekspor tema `lokal-*` terpasang menjadi folder paket `<folder>/<as>/`
 * yang siap diberi hash dan ditandatangani `pondok:theme-sign`. Logikanya ada di `ExportPortalTheme`.
 */
#[Signature('pondok:theme-export {source : Slug tema lokal-* terpasang} {folder : Folder tujuan (induk dari <as>)} {--as= : Slug tema hasil ekspor (tanpa awalan lokal-)}')]
#[Description('Mengekspor tema lokal menjadi folder paket penerbit')]
class ThemeExportCommand extends Command
{
    public function handle(ExportPortalTheme $export): int
    {
        try {
            $path = $export->handle((string) $this->argument('source'), (string) $this->argument('folder'), (string) $this->option('as'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(__('pondok.theme_export.done', ['path' => $path]));

        return self::SUCCESS;
    }
}
