<?php

namespace App\Filament\Resources\Students\Tables;

use App\Filament\Resources\Students\StudentResource;
use App\Models\Student;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StudentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['unit', 'cohort', 'currentEnrollment.classroom']))
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
                    ->toggleable(),
                TextColumn::make('currentEnrollment.classroom.name')
                    ->label(__('pondok.student.current_classroom'))
                    ->placeholder('-'),
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
