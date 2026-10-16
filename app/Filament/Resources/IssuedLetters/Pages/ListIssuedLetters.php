<?php

namespace App\Filament\Resources\IssuedLetters\Pages;

use App\Filament\Resources\IssuedLetters\IssuedLetterResource;
use Filament\Resources\Pages\ListRecords;

class ListIssuedLetters extends ListRecords
{
    protected static string $resource = IssuedLetterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            IssuedLetterResource::issueAction(),
            IssuedLetterResource::configureNumberingAction(),
        ];
    }
}
