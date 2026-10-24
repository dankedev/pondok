<?php

namespace App\Filament\Resources\DisciplineLevels\Pages;

use App\Actions\Kedisiplinan\SaveDisciplineLevel;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Filament\Resources\DisciplineLevels\DisciplineLevelResource;
use App\Models\DisciplineLevel;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditDisciplineLevel extends EditRecord
{
    use ShowsActionErrorsOnPageForm;

    protected static string $resource = DisciplineLevelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DisciplineLevelResource::deleteAction(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var DisciplineLevel $record */
        return $this->runShowingPageFormErrors(fn (): DisciplineLevel => app(SaveDisciplineLevel::class)->handle(DisciplineLevelResource::user(), $data, $record));
    }
}
