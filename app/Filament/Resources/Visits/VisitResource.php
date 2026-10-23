<?php

namespace App\Filament\Resources\Visits;

use App\Filament\Concerns\BelongsToModule;
use App\Filament\Resources\Visits\Pages\CreateVisit;
use App\Filament\Resources\Visits\Pages\EditVisit;
use App\Filament\Resources\Visits\Pages\ListVisits;
use App\Filament\Resources\Visits\Schemas\VisitForm;
use App\Filament\Resources\Visits\Tables\VisitsTable;
use App\Models\User;
use App\Models\Visit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Kunjungan wali / sambangan (F024). Catat dan ubah lewat `RecordVisit`/`UpdateVisit`; tanpa hapus.
 * Cakupan jenjang dari global scope `Visit`.
 */
class VisitResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Visit::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static ?int $navigationSort = 2;

    public static function getModule(): string
    {
        return 'perizinan';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.visit.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.visit.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.perizinan');
    }

    public static function form(Schema $schema): Schema
    {
        return VisitForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VisitsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVisits::route('/'),
            'create' => CreateVisit::route('/create'),
            'edit' => EditVisit::route('/{record}/edit'),
        ];
    }

    public static function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
