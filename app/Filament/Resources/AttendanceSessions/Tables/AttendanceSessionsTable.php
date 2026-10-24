<?php

namespace App\Filament\Resources\AttendanceSessions\Tables;

use App\Enums\AttendanceStatus;
use App\Filament\Resources\AttendanceSessions\AttendanceSessionResource;
use App\Filament\Resources\AttendanceSessions\Schemas\AttendanceSessionForm;
use App\Models\AttendanceSession;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AttendanceSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('held_on')->orderByDesc('id'))
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['classroom.unit', 'recorder'])
                ->withCount(collect(AttendanceStatus::cases())->mapWithKeys(fn (AttendanceStatus $status): array => [
                    "records as {$status->value}_count" => fn (Builder $query) => $query->where('status', $status->value),
                ])->all()))
            ->columns([
                TextColumn::make('held_on')
                    ->label(__('pondok.attendance.fields.held_on'))
                    ->date('j M Y')
                    ->sortable(),
                TextColumn::make('classroom.name')
                    ->label(__('pondok.attendance.fields.classroom_id')),
                TextColumn::make('classroom.unit.name')
                    ->label(__('pondok.attendance.fields.unit')),
                ...array_map(fn (AttendanceStatus $status): TextColumn => TextColumn::make("{$status->value}_count")
                    ->label($status->label())
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? $status->color() : 'gray')
                    ->alignEnd(), AttendanceStatus::cases()),
                TextColumn::make('recorder.name')
                    ->label(__('pondok.attendance.fields.recorded_by'))
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('classroom_id')
                    ->label(__('pondok.attendance.fields.classroom_id'))
                    ->options(fn (): array => AttendanceSessionForm::classroomLabels(
                        AttendanceSession::visibleClassrooms(AttendanceSessionResource::user())->orderByDesc('academic_year_id'),
                    )),
            ])
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentCheck)
            ->emptyStateHeading(__('pondok.attendance.empty.heading'))
            ->emptyStateDescription(__('pondok.attendance.empty.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('pondok.attendance.create_action'))
                    ->url(fn (): string => AttendanceSessionResource::getUrl('create')),
            ])
            ->recordActions([
                EditAction::make()
                    ->authorize(fn (AttendanceSession $record): bool => AttendanceSessionResource::canEdit($record)),
            ]);
    }
}
