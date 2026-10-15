<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->with(['roles', 'units']))
            ->columns([
                TextColumn::make('name')
                    ->label(__('pondok.common.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('pondok.user.email'))
                    ->searchable(),
                TextColumn::make('roles.name')
                    ->label(__('pondok.user.roles'))
                    ->badge(),
                TextColumn::make('units.code')
                    ->label(__('pondok.user.units'))
                    ->badge()
                    ->placeholder(__('pondok.user.all_units')),
                IconColumn::make('is_active')
                    ->label(__('pondok.user.is_active'))
                    ->boolean(),
                TextColumn::make('last_login_at')
                    ->label(__('pondok.user.last_login_at'))
                    ->dateTime()
                    ->sortable()
                    ->placeholder('-'),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('pondok.user.is_active')),
                SelectFilter::make('roles')
                    ->label(__('pondok.user.roles'))
                    ->relationship('roles', 'name')
                    ->preload(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
