<?php

namespace App\Filament\Resources\Guardians;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Resources\Documents\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Guardians\Pages\CreateGuardian;
use App\Filament\Resources\Guardians\Pages\EditGuardian;
use App\Filament\Resources\Guardians\Pages\ListGuardians;
use App\Filament\Resources\Guardians\RelationManagers\StudentsRelationManager;
use App\Filament\Resources\Guardians\Schemas\GuardianForm;
use App\Filament\Resources\Guardians\Tables\GuardiansTable;
use App\Models\Guardian;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class GuardianResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Guardian::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 4;

    public static function getModule(): string
    {
        return 'akademik';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.guardian.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.guardian.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.akademik');
    }

    public static function form(Schema $schema): Schema
    {
        return GuardianForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GuardiansTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            StudentsRelationManager::class,
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGuardians::route('/'),
            'create' => CreateGuardian::route('/create'),
            'edit' => EditGuardian::route('/{record}/edit'),
        ];
    }
}
