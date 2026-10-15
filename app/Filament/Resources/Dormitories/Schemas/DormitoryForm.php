<?php

namespace App\Filament\Resources\Dormitories\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DormitoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('pondok.dormitory.section_identity'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('code')
                            ->label(__('pondok.dormitory.code'))
                            ->helperText(__('pondok.dormitory.code_helper'))
                            ->required()
                            ->maxLength(20)
                            ->unique(ignoreRecord: true)
                            ->validationMessages(['unique' => __('pondok.dormitory.unique')]),
                        TextInput::make('name')
                            ->label(__('pondok.dormitory.name'))
                            ->helperText(__('pondok.dormitory.name_helper'))
                            ->required()
                            ->maxLength(255),
                        Select::make('gender')
                            ->label(__('pondok.dormitory.gender'))
                            ->options(__('pondok.dormitory.genders'))
                            ->required(),
                        TextInput::make('location')
                            ->label(__('pondok.dormitory.location'))
                            ->helperText(__('pondok.dormitory.location_helper'))
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label(__('pondok.common.is_active'))
                            ->default(true),
                    ]),
            ]);
    }
}
