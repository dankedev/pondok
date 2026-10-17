<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('pondok.user.section_account'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('pondok.common.name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label(__('pondok.user.email'))
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('password')
                            ->label(__('pondok.user.password'))
                            ->password()
                            ->revealable()
                            ->minLength(8)
                            ->rules([Password::min(8)->letters()->numbers()])
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText(fn (string $operation): ?string => $operation === 'edit' ? __('pondok.user.password_helper') : null),
                        Toggle::make('is_active')
                            ->label(__('pondok.user.is_active'))
                            ->helperText(__('pondok.user.is_active_helper'))
                            ->default(true)
                            // Mencegah admin mengunci dirinya sendiri.
                            ->disabled(fn (?User $record): bool => $record?->is(auth()->user()) ?? false),
                    ]),
                Section::make(__('pondok.user.section_access'))
                    ->schema([
                        Select::make('roles')
                            ->label(__('pondok.user.roles'))
                            ->relationship(
                                'roles',
                                'name',
                                // Hanya super-admin yang boleh memberi peran super-admin.
                                modifyQueryUsing: fn (Builder $query): Builder => auth()->user()?->hasRole(RolePermissionSeeder::SUPER_ADMIN)
                                    ? $query
                                    : $query->where('name', '!=', RolePermissionSeeder::SUPER_ADMIN),
                            )
                            ->multiple()
                            ->preload(),
                        CheckboxList::make('units')
                            ->label(__('pondok.user.units'))
                            ->helperText(__('pondok.user.units_helper'))
                            ->relationship('units', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->orderBy('sort_order'))
                            ->columns(3),
                    ]),
            ]);
    }
}
