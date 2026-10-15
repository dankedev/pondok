<?php

namespace App\Filament\Resources\Staff\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class StaffTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('pondok.common.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('nip')
                    ->label(__('pondok.staff.nip'))
                    ->searchable(),
                TextColumn::make('gender')
                    ->label(__('pondok.common.gender'))
                    ->formatStateUsing(fn (string $state): string => __("pondok.common.genders.{$state}")),
                TextColumn::make('position')
                    ->label(__('pondok.staff.position'))
                    ->searchable(),
                TextColumn::make('phone')
                    ->label(__('pondok.staff.phone')),
                TextColumn::make('user.email')
                    ->label(__('pondok.staff.user'))
                    ->placeholder('-'),
                IconColumn::make('is_active')
                    ->label(__('pondok.common.is_active'))
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('pondok.common.is_active')),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
