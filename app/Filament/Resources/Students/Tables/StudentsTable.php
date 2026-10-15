<?php

namespace App\Filament\Resources\Students\Tables;

use App\Filament\Resources\Students\StudentResource;
use App\Models\Setting;
use App\Models\Student;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StudentsTable
{
    public static function configure(Table $table): Table
    {
        // Kolom kamar dan eager-load-nya hanya ada saat modul asrama aktif.
        $asramaEnabled = Setting::moduleEnabled('asrama');

        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['unit', 'cohort', 'currentEnrollment.classroom'])
                ->when($asramaEnabled, fn (Builder $query) => $query->with('activeRoom.dormitory')))
            ->columns([
                TextColumn::make('nis')
                    ->label(__('pondok.student.nis'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('pondok.common.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('gender')
                    ->label(__('pondok.common.gender'))
                    ->formatStateUsing(fn (string $state): string => __("pondok.common.genders.{$state}")),
                TextColumn::make('unit.name')
                    ->label(__('pondok.common.unit')),
                TextColumn::make('cohort.name')
                    ->label(__('pondok.student.cohort'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('currentEnrollment.classroom.name')
                    ->label(__('pondok.student.current_classroom'))
                    ->placeholder('-'),
                TextColumn::make('activeRoom.name')
                    ->label(__('pondok.student.room'))
                    ->visible($asramaEnabled)
                    ->formatStateUsing(fn (Student $record): string => "{$record->activeRoom->dormitory->name} — {$record->activeRoom->name}")
                    ->placeholder(__('pondok.room_assignment.non_resident')),
                TextColumn::make('status')
                    ->label(__('pondok.student.status'))
                    ->badge()
                    ->color(fn (string $state): string => $state === Student::STATUS_ACTIVE ? 'success' : 'gray')
                    ->formatStateUsing(fn (string $state): string => __("pondok.student.statuses.{$state}")),
            ])
            ->filters([
                SelectFilter::make('unit_id')
                    ->label(__('pondok.common.unit'))
                    ->relationship('unit', 'name', fn (Builder $query) => $query->accessible()),
                SelectFilter::make('cohort_id')
                    ->label(__('pondok.student.cohort'))
                    ->relationship('cohort', 'name'),
                SelectFilter::make('status')
                    ->label(__('pondok.student.status'))
                    ->options(__('pondok.student.statuses'))
                    ->default(Student::STATUS_ACTIVE),
                SelectFilter::make('gender')
                    ->label(__('pondok.common.gender'))
                    ->options(__('pondok.common.genders')),
                TrashedFilter::make(),
                // Kelengkapan berkas wajib (modul Dokumen); tidak tersedia saat modul mati.
                ...(Setting::moduleEnabled('dokumen') ? [
                    TernaryFilter::make('required_documents')
                        ->label(__('pondok.document.completeness_filter'))
                        ->trueLabel(__('pondok.document.completeness_labels.1'))
                        ->falseLabel(__('pondok.document.completeness_labels.0'))
                        ->queries(
                            true: fn (Builder $query): Builder => $query->withCompleteDocuments(),
                            false: fn (Builder $query): Builder => $query->withIncompleteDocuments(),
                        ),
                ] : []),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    StudentResource::changeUnitAction(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
