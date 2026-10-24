<?php

namespace App\Filament\Resources\DisciplineLevels\Pages;

use App\Actions\Kedisiplinan\SaveDisciplineLevel;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Filament\Resources\DisciplineLevels\DisciplineLevelResource;
use App\Models\DisciplineLevel;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDisciplineLevel extends CreateRecord
{
    use ShowsActionErrorsOnPageForm;

    protected static string $resource = DisciplineLevelResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->runShowingPageFormErrors(fn (): DisciplineLevel => app(SaveDisciplineLevel::class)->handle(DisciplineLevelResource::user(), $data));
    }
}
