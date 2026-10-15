<?php

namespace App\Filament\Resources\Dormitories\Tables;

use App\Models\Dormitory;
use App\Models\RoomAssignment;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DormitoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount(['rooms', 'supervisors'])
                ->withExists('feeRates')
                ->withSum('rooms', 'capacity')
                ->addSelect(['occupied_count' => RoomAssignment::withoutUnitScope()
                    ->selectRaw('count(*)')
                    ->join('rooms', 'rooms.id', '=', 'room_assignments.room_id')
                    ->whereColumn('rooms.dormitory_id', 'dormitories.id')
                    ->whereNull('room_assignments.end_on'),
                ]))
            ->columns([
                TextColumn::make('code')
                    ->label(__('pondok.dormitory.code'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('pondok.dormitory.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('gender')
                    ->label(__('pondok.dormitory.gender'))
                    ->formatStateUsing(fn (string $state): string => __("pondok.dormitory.genders.{$state}")),
                TextColumn::make('rooms_count')
                    ->label(__('pondok.dormitory.rooms_count'))
                    ->numeric(),
                TextColumn::make('occupied_count')
                    ->label(__('pondok.dormitory.occupancy'))
                    ->formatStateUsing(fn (Dormitory $record): string => ((int) $record->occupied_count).' / '.((int) $record->rooms_sum_capacity)),
                TextColumn::make('location')
                    ->label(__('pondok.dormitory.location'))
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label(__('pondok.common.is_active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('gender')
                    ->label(__('pondok.dormitory.gender'))
                    ->options(__('pondok.dormitory.genders')),
                TernaryFilter::make('is_active')
                    ->label(__('pondok.common.is_active')),
            ])
            ->emptyStateHeading(__('pondok.dormitory.empty'))
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (Dormitory $record): bool => $record->rooms_count > 0 || $record->supervisors_count > 0 || $record->fee_rates_exists),
            ]);
    }
}
