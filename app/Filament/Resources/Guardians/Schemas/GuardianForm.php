<?php

namespace App\Filament\Resources\Guardians\Schemas;

use App\Models\Guardian;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class GuardianForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ...self::fields(),
                // Akun portal hanya ditautkan lewat aksi "Buat akun portal" (F022); di sini baca saja.
                TextEntry::make('linked_account')
                    ->label(__('pondok.portal_wali.linked_account'))
                    ->state(fn (?Guardian $record): ?string => $record?->user?->email)
                    ->placeholder(__('pondok.portal_wali.no_linked_account'))
                    ->visibleOn('edit'),
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
