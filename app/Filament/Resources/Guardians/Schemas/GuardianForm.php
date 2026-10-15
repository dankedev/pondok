<?php

namespace App\Filament\Resources\Guardians\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class GuardianForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ...self::fields(),
                Select::make('user_id')
                    ->label(__('pondok.guardian.user'))
                    ->relationship('user', 'name')
                    ->searchable(['name', 'email'])
                    ->unique(ignoreRecord: true),
            ]);
    }

    /**
     * Field data wali, dipakai juga saat menambah wali dari halaman santri.
     *
     * @return list<TextInput|Textarea>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('name')
                ->label(__('pondok.common.name'))
                ->required()
                ->maxLength(255),
            TextInput::make('phone')
                ->label(__('pondok.common.phone'))
                ->tel()
                ->required()
                ->maxLength(30),
            TextInput::make('occupation')
                ->label(__('pondok.guardian.occupation'))
                ->maxLength(100),
            Textarea::make('address')
                ->label(__('pondok.common.address'))
                ->rows(2),
        ];
    }
}
