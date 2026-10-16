<?php

namespace App\Filament\Resources\Achievements\Pages;

use App\Actions\Prestasi\SaveAchievement;
use App\Filament\Concerns\ShowsActionErrorsOnPageForm;
use App\Filament\Resources\Achievements\AchievementResource;
use App\Models\Achievement;
use App\Models\Student;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * `?student=<id>` (dari tab prestasi santri) mengisi peserta pertama.
 */
class CreateAchievement extends CreateRecord
{
    use ShowsActionErrorsOnPageForm;

    protected static string $resource = AchievementResource::class;

    protected function afterFill(): void
    {
        $studentId = request()->query('student');

        if (! is_numeric($studentId) || ! Student::query()->whereKey((int) $studentId)->exists()) {
            return;
        }

        $this->data['participants'] = [
            (string) Str::uuid() => ['student_id' => (int) $studentId, 'rank' => null, 'role' => null],
        ];
    }

    protected function handleRecordCreation(array $data): Model
    {
        return $this->runShowingPageFormErrors(fn (): Achievement => app(SaveAchievement::class)->handle(AchievementResource::actionData($data), AchievementResource::user()));
    }
}
