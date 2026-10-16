<?php

namespace App\Filament\Resources\Achievements\Pages;

use App\Actions\Prestasi\SaveAchievement;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Filament\Resources\Achievements\AchievementResource;
use App\Models\Achievement;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EditAchievement extends EditRecord
{
    use ShowsActionErrorsOnPageForm;

    protected static string $resource = AchievementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AchievementResource::verifyAction(),
            AchievementResource::publishAction(),
            AchievementResource::deleteAction(),
        ];
    }

    /**
     * Peserta lama (termasuk santri terarsip) beserta peringkat dan peran.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Achievement $record */
        $record = $this->getRecord();

        $data['achieved_on'] = $record->achieved_on?->toDateString();
        $data['participants'] = DB::table('achievement_student')
            ->join('students', 'students.id', '=', 'achievement_student.student_id')
            ->where('achievement_student.achievement_id', $record->id)
            ->orderBy('students.name')
            ->get(['achievement_student.student_id', 'achievement_student.rank', 'achievement_student.role'])
            ->mapWithKeys(fn (object $row): array => [(string) Str::uuid() => [
                'student_id' => (int) $row->student_id,
                'rank' => $row->rank,
                'role' => $row->role,
            ]])
            ->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Achievement $record */
        return $this->runShowingPageFormErrors(function () use ($record, $data): Achievement {
            try {
                return app(SaveAchievement::class)->handle(AchievementResource::actionData($data), AchievementResource::user(), $record);
            } catch (ValidationException $exception) {
                // Galat tanpa field form (cakupan jenjang) tampil sebagai notifikasi.
                if (array_key_exists('achievement', $exception->errors())) {
                    Notification::make()->title((string) collect($exception->errors())->flatten()->first())->danger()->send();

                    throw new Halt;
                }

                throw $exception;
            }
        });
    }
}
