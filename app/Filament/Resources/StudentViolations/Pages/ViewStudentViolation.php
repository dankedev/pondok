<?php

namespace App\Filament\Resources\StudentViolations\Pages;

use App\Filament\Resources\StudentViolations\StudentViolationResource;
use App\Models\StudentViolation;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewStudentViolation extends ViewRecord
{
    protected static string $resource = StudentViolationResource::class;

    /**
     * Tanpa hak lihat daftar → 403 sebelum record dicari, karena global scope `visible`
     * menyembunyikan semua catatan dari user tanpa cakupan (hasilnya 404 yang menyesatkan).
     */
    public function mount(int|string $record): void
    {
        abort_unless(StudentViolationResource::canViewAny(), 403);

        parent::mount($record);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (StudentViolation $record): bool => ! $record->isCancelled()),
            StudentViolationResource::cancelAction(),
        ];
    }
}
