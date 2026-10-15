<?php

namespace App\Filament\Resources\Classrooms\Pages;

use App\Actions\Akademik\CopyClassroomsFromYear;
use App\Filament\Resources\Classrooms\ClassroomResource;
use App\Models\AcademicYear;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageClassrooms extends ManageRecords
{
    protected static string $resource = ClassroomResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('copy')
                ->label(__('pondok.classroom.copy'))
                ->icon(Heroicon::OutlinedDocumentDuplicate)
                ->color('gray')
                ->authorize(fn (): bool => ClassroomResource::canCreate())
                ->modalDescription(__('pondok.classroom.copy_helper'))
                ->schema([
                    Select::make('from_academic_year_id')
                        ->label(__('pondok.classroom.copy_from'))
                        ->options(fn (): array => AcademicYear::query()->orderByDesc('starts_on')->pluck('name', 'id')->all())
                        ->required(),
                    Select::make('to_academic_year_id')
                        ->label(__('pondok.classroom.copy_to'))
                        ->options(fn (): array => AcademicYear::query()->orderByDesc('starts_on')->pluck('name', 'id')->all())
                        ->default(fn (): ?int => AcademicYear::current()?->id)
                        ->different('from_academic_year_id')
                        ->required(),
                ])
                ->action(function (array $data, CopyClassroomsFromYear $copy): void {
                    $unitIds = auth()->user()->scopedUnitIds();

                    $count = $copy->handle(
                        AcademicYear::query()->findOrFail($data['from_academic_year_id']),
                        AcademicYear::query()->findOrFail($data['to_academic_year_id']),
                        $unitIds === [] ? null : $unitIds,
                    );

                    Notification::make()
                        ->title(__('pondok.classroom.copied', ['count' => $count]))
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
