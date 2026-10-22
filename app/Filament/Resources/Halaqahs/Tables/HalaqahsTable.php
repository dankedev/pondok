<?php

namespace App\Filament\Resources\Halaqahs\Tables;

use App\Models\AcademicYear;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HalaqahsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['academicYear', 'unit', 'teacher'])
                ->withCount(['members as active_members_count' => fn (Builder $members) => $members->whereNull('left_on')]))
            ->columns([
                TextColumn::make('name')
                    ->label(__('pondok.tahfidz.halaqah.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('teacher.name')
                    ->label(__('pondok.tahfidz.halaqah.teacher')),
                TextColumn::make('unit.name')
                    ->label(__('pondok.common.unit'))
                    ->placeholder(__('pondok.tahfidz.cross_unit')),
                TextColumn::make('gender')
                    ->label(__('pondok.tahfidz.halaqah.gender'))
                    ->formatStateUsing(fn (string $state): string => __("pondok.tahfidz.genders.{$state}")),
                TextColumn::make('active_members_count')
                    ->label(__('pondok.tahfidz.halaqah.active_members'))
                    ->numeric(),
                TextColumn::make('academicYear.name')
                    ->label(__('pondok.common.academic_year'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('schedule')
                    ->label(__('pondok.tahfidz.halaqah.schedule'))
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label(__('pondok.common.is_active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('academic_year_id')
                    ->label(__('pondok.common.academic_year'))
                    ->options(fn (): array => AcademicYear::query()->orderByDesc('starts_on')->pluck('name', 'id')->all())
                    ->default(fn (): ?int => AcademicYear::current()?->id),
                TernaryFilter::make('is_active')
                    ->label(__('pondok.common.is_active')),
            ])
            ->emptyStateHeading(fn (): string => auth()->user()->can('update:halaqah') ? __('pondok.tahfidz.halaqah.empty') : __('pondok.tahfidz.halaqah.empty_teacher'))
            ->emptyStateIcon(Heroicon::OutlinedBookOpen)
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
