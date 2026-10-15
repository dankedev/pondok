<?php

namespace App\Filament\Resources\Staff\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class StaffForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('pondok.common.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('nip')
                    ->label(__('pondok.staff.nip'))
                    ->maxLength(50)
                    ->unique(ignoreRecord: true),
                Select::make('gender')
                    ->label(__('pondok.common.gender'))
                    ->options(__('pondok.common.genders'))
                    ->required(),
                TextInput::make('phone')
                    ->label(__('pondok.staff.phone'))
                    ->tel()
                    ->maxLength(30),
                TextInput::make('position')
                    ->label(__('pondok.staff.position'))
                    ->datalist(__('pondok.staff.positions'))
                    ->required()
                    ->maxLength(100),
                Select::make('user_id')
                    ->label(__('pondok.staff.user'))
                    ->helperText(__('pondok.staff.user_helper'))
                    ->relationship('user', 'name')
                    ->searchable(['name', 'email'])
                    ->preload()
                    ->unique(ignoreRecord: true),
                Toggle::make('is_active')
                    ->label(__('pondok.common.is_active'))
                    ->default(true),
            ]);
    }
}
