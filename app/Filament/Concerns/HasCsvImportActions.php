<?php

namespace App\Filament\Concerns;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ImportAction;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use League\Csv\Bom;
use League\Csv\Writer;
use SplTempFileObject;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Aksi "Unduh template CSV" dan "Import" untuk halaman daftar yang mengimpor CSV.
 */
trait HasCsvImportActions
{
    /**
     * @param  class-string<Importer>  $importer
     * @param  Closure|string  $modalDescription  teks biasa, atau closure yang mengembalikan teks
     * @return array{Action, ImportAction}
     */
    protected function csvImportActions(string $importer, string $templateFileName, Closure|string $modalDescription, Closure $visible, int $maxRows = 2000): array
    {
        $user = null;

        return [
            Action::make('downloadImportTemplate')
                ->label(__('pondok.import.download_template'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->downloadCsvImportTemplate($importer, $templateFileName))
                ->visible($visible),
            ImportAction::make()
                ->importer($importer)
                ->chunkSize(100)
                ->maxRows($maxRows)
                ->modalDescription(fn (ImportAction $action): HtmlString => new HtmlString(
                    e($modalDescription instanceof Closure ? $modalDescription() : $modalDescription)
                    .'<br>'.$action->getModalAction('downloadExample')->toHtml()
                ))
                // Antrean `sync` menjalankan job di request ini dan job itu melepas
                // guard di akhirnya; pulihkan pengguna agar halaman tetap bisa dirender.
                ->before(function () use (&$user): void {
                    $user = auth()->user();
                })
                ->after(function () use (&$user): void {
                    if ($user !== null && auth()->guest()) {
                        auth()->setUser($user);
                    }
                })
                ->visible($visible),
        ];
    }

    /**
     * Template CSV dengan judul kolom berbahasa Indonesia dan satu baris contoh fiktif,
     * sama dengan contoh di jendela import.
     *
     * @param  class-string<Importer>  $importer
     */
    private function downloadCsvImportTemplate(string $importer, string $fileName): StreamedResponse
    {
        $columns = $importer::getColumns();

        $csv = Writer::createFromFileObject(new SplTempFileObject);
        $csv->insertOne(array_map(fn (ImportColumn $column): string => $column->getExampleHeader(), $columns));
        $csv->insertOne(array_map(fn (ImportColumn $column): string => (string) collect($column->getExamples())->first(), $columns));

        return response()->streamDownload(function () use ($csv): void {
            $csv->setOutputBOM(Bom::Utf8);

            echo $csv->toString();
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
