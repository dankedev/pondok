<?php

namespace App\Filament\Resources\Dormitories;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Resources\Dormitories\Pages\CreateDormitory;
use App\Filament\Resources\Dormitories\Pages\EditDormitory;
use App\Filament\Resources\Dormitories\Pages\ListDormitories;
use App\Filament\Resources\Dormitories\RelationManagers\RoomsRelationManager;
use App\Filament\Resources\Dormitories\RelationManagers\SupervisorsRelationManager;
use App\Filament\Resources\Dormitories\Schemas\DormitoryForm;
use App\Filament\Resources\Dormitories\Tables\DormitoriesTable;
use App\Models\Dormitory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DormitoryResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Dormitory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    public static function getModule(): string
    {
        return 'asrama';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.dormitory.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.dormitory.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.asrama');
    }

    public static function form(Schema $schema): Schema
    {
        return DormitoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DormitoriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RoomsRelationManager::class,
            SupervisorsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDormitories::route('/'),
            'create' => CreateDormitory::route('/create'),
            'edit' => EditDormitory::route('/{record}/edit'),
        ];
    }

    /**
     * Semua FK ke asrama `restrictOnDelete`: asrama hanya bisa dihapus bila
     * belum punya kamar, riwayat musyrif, maupun tarif.
     */
    public static function canBeDeleted(Dormitory $dormitory): bool
    {
        return ! $dormitory->rooms()->exists()
            && ! $dormitory->supervisors()->exists()
            && ! $dormitory->feeRates()->exists();
    }
}
