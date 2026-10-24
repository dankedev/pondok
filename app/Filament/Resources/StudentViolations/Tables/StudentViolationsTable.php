<?php

namespace App\Filament\Resources\StudentViolations\Tables;

use App\Enums\ViolationCategory;
use App\Filament\Resources\StudentViolations\StudentViolationResource;
use App\Filament\Resources\ViolationTypes\ViolationTypeResource;
use App\Models\AcademicYear;
use App\Models\Student;
use App\Models\StudentViolation;
use App\Models\Unit;
use App\Models\ViolationType;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StudentViolationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('occurred_on')->orderByDesc('id'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['student.unit', 'violationType', 'recorder']))
            ->columns([
                TextColumn::make('occurred_on')
                    ->label(__('pondok.kedisiplinan.fields.occurred_on'))
                    ->date('j M Y')
                    ->sortable(),
                TextColumn::make('student.name')
                    ->label(__('pondok.kedisiplinan.fields.student_id'))
                    ->description(fn (StudentViolation $record): ?string => $record->student?->nis)
                    ->searchable(['name', 'nis']),
                TextColumn::make('student.unit.name')
                    ->label(__('pondok.kedisiplinan.violation.unit')),
                TextColumn::make('violationType.name')
                    ->label(__('pondok.kedisiplinan.fields.violation_type_id'))
                    ->wrap(),
                TextColumn::make('violationType.category')
                    ->label(__('pondok.kedisiplinan.fields.category'))
                    ->badge()
                    ->formatStateUsing(fn (ViolationCategory $state): string => $state->label())
                    ->color(fn (ViolationCategory $state): string => $state->color()),
                TextColumn::make('points')
                    ->label(__('pondok.kedisiplinan.fields.points'))
                    ->numeric()
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('recorder.name')
                    ->label(__('pondok.kedisiplinan.violation.recorded_by'))
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label(__('pondok.kedisiplinan.violation.status'))
                    ->state(fn (StudentViolation $record): ?string => $record->isCancelled() ? __('pondok.kedisiplinan.violation.cancelled') : null)
                    ->badge()
                    ->color('gray')
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('academic_year_id')
                    ->label(__('pondok.kedisiplinan.violation.academic_year'))
                    ->options(fn (): array => AcademicYear::query()->orderByDesc('starts_on')->pluck('name', 'id')->all())
                    ->default(fn (): ?int => AcademicYear::current()?->id),
                SelectFilter::make('unit')
                    ->label(__('pondok.kedisiplinan.violation.unit'))
                    ->options(fn (): array => Unit::query()->accessible()->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query : $query->whereIn(
                        'student_id',
                        Student::withoutUnitScope()->withTrashed()->where('unit_id', (int) $data['value'])->select('id'),
                    )),
                SelectFilter::make('category')
                    ->label(__('pondok.kedisiplinan.fields.category'))
                    ->options(ViolationTypeResource::categoryOptions())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query : $query->whereIn(
                        'violation_type_id',
                        ViolationType::query()->where('category', $data['value'])->select('id'),
                    )),
                SelectFilter::make('violation_type_id')
                    ->label(__('pondok.kedisiplinan.fields.violation_type_id'))
                    ->options(fn (): array => ViolationType::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                TernaryFilter::make('cancelled')
                    ->label(__('pondok.kedisiplinan.violation.filter_status'))
                    ->trueLabel(__('pondok.kedisiplinan.violation.filter_cancelled'))
                    ->falseLabel(__('pondok.kedisiplinan.violation.filter_counted'))
                    ->nullable()
                    ->attribute('cancelled_at'),
            ])
            ->emptyStateIcon(Heroicon::OutlinedShieldExclamation)
            ->emptyStateHeading(__('pondok.kedisiplinan.violation.empty.heading'))
            ->emptyStateDescription(__('pondok.kedisiplinan.violation.empty.description'))
            ->recordActions([
                EditAction::make()
                    ->authorize(fn (StudentViolation $record): bool => StudentViolationResource::canEdit($record))
                    ->visible(fn (StudentViolation $record): bool => ! $record->isCancelled()),
                ViewAction::make(),
                ActionGroup::make([
                    StudentViolationResource::cancelAction(),
                ]),
            ]);
    }
}
