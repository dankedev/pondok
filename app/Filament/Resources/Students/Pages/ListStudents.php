<?php

namespace App\Filament\Resources\Students\Pages;

use App\Filament\Imports\StudentImporter;
use App\Filament\Resources\Students\StudentResource;
use App\Models\Student;
use App\Models\Unit;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Actions\Imports\ImportColumn;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use League\Csv\Bom;
use League\Csv\Writer;
use SplTempFileObject;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListStudents extends ListRecords
{
    protected static string $resource = StudentResource::class;

    protected function getHeaderActions(): array
    {
        $canImport = fn (): bool => auth()->user()?->can('import', Student::class) ?? false;

        return [
            Action::make('downloadImportTemplate')
                ->label(__('pondok.akademik.import.download_template'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->downloadImportTemplate())
                ->visible($canImport),
            ImportAction::make()
                ->importer(StudentImporter::class)
                ->chunkSize(100)
                ->maxRows(2000)
                ->modalDescription(fn (ImportAction $action): HtmlString => new HtmlString(
                    e(__('pondok.akademik.import.modal_description', [
                        'units' => Unit::query()->orderBy('sort_order')->pluck('code')->implode(', '),
                    ])).'<br>'.$action->getModalAction('downloadExample')->toHtml()
                ))
                ->visible($canImport),
            CreateAction::make(),
        ];
    }

    /**
     * Template CSV dengan judul kolom berbahasa Indonesia dan satu baris contoh fiktif,
     * sama dengan contoh di jendela import.
     */
    private function downloadImportTemplate(): StreamedResponse
    {
        $columns = StudentImporter::getColumns();

        $csv = Writer::createFromFileObject(new SplTempFileObject);
        $csv->insertOne(array_map(fn (ImportColumn $column): string => $column->getExampleHeader(), $columns));
        $csv->insertOne(array_map(fn (ImportColumn $column): string => (string) collect($column->getExamples())->first(), $columns));

        return response()->streamDownload(function () use ($csv): void {
            $csv->setOutputBOM(Bom::Utf8);

            echo $csv->toString();
        }, __('pondok.akademik.import.template_file_name'), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
