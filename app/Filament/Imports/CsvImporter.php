<?php

namespace App\Filament\Imports;

use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Notifications\Notification;

/**
 * Dasar importer CSV Pondok: judul kolom dari lang, file baris gagal aman dari
 * formula, dan notifikasi hasil tersimpan di database.
 */
abstract class CsvImporter extends Importer
{
    protected static bool $shouldPreventFormulaInjection = true;

    /**
     * Awalan kunci lang untuk judul kolom, mis. `pondok.akademik.import.columns`.
     */
    abstract protected static function columnLangPrefix(): string;

    /**
     * @param  list<string>  $rules
     */
    protected static function column(string $name, bool $required = false, array $rules = []): ImportColumn
    {
        $label = __(static::columnLangPrefix().".{$name}");

        return ImportColumn::make($name)
            ->label($label)
            ->exampleHeader($label)
            ->guess([$label, mb_strtolower($label)])
            ->requiredMapping($required)
            ->rules([$required ? 'required' : 'nullable', ...$rules])
            ->fillRecordUsing(fn () => null);
    }

    /**
     * Antrean `sync` hanya mengirim notifikasi sesi (toast); simpan juga ke
     * database supaya hasil impor tetap ada di lonceng notifikasi.
     */
    public static function modifyCompletedNotification(Notification $notification, Import $import): Notification
    {
        if (config('queue.default') === 'sync') {
            $notification->sendToDatabase($import->user);
        }

        return $notification;
    }
}
