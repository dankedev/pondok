<?php

namespace App\Filament\Resources\Students\Pages;

use App\Actions\Akademik\CreateStudent as CreateStudentAction;
use App\Filament\Resources\Students\StudentResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateStudent extends CreateRecord
{
    protected static string $resource = StudentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(CreateStudentAction::class)->handle($data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => ["data.{$field}" => $messages])->all(),
            );
        }
    }
}
