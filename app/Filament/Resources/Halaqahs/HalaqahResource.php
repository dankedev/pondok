<?php

namespace App\Filament\Resources\Halaqahs;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Resources\Halaqahs\Pages\CreateHalaqah;
use App\Filament\Resources\Halaqahs\Pages\EditHalaqah;
use App\Filament\Resources\Halaqahs\Pages\ListHalaqahs;
use App\Filament\Resources\Halaqahs\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Halaqahs\Schemas\HalaqahForm;
use App\Filament\Resources\Halaqahs\Tables\HalaqahsTable;
use App\Models\Halaqah;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Halaqah tahfidz (F023). Dikelola pemegang `update:halaqah`; ustadz pengampu hanya melihat
 * halaqah yang ia ampu (`Halaqah::visibleTo`). Tidak dihapus, cukup dinonaktifkan.
 */
class HalaqahResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Halaqah::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function getModule(): string
    {
        return 'tahfidz';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.tahfidz.halaqah.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.tahfidz.halaqah.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.tahfidz');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return HalaqahForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HalaqahsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHalaqahs::route('/'),
            'create' => CreateHalaqah::route('/create'),
            'edit' => EditHalaqah::route('/{record}/edit'),
        ];
    }
}
