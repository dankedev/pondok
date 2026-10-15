<?php

namespace App\Filament\Resources\Guardians\Tables;

use App\Models\Guardian;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GuardiansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('students'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('pondok.common.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->label(__('pondok.common.phone'))
                    ->searchable(),
                TextColumn::make('occupation')
                    ->label(__('pondok.guardian.occupation'))
                    ->placeholder('-'),
                TextColumn::make('students_count')
                    ->label(__('pondok.guardian.students_count')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (Guardian $record): bool => $record->students_count > 0),
            ]);
    }
}
