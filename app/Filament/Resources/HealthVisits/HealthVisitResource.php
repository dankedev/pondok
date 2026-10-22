<?php

namespace App\Filament\Resources\HealthVisits;

use App\Actions\Kesehatan\DeleteHealthVisit;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Resources\HealthVisits\Pages\CreateHealthVisit;
use App\Filament\Resources\HealthVisits\Pages\EditHealthVisit;
use App\Filament\Resources\HealthVisits\Pages\ListHealthVisits;
use App\Filament\Resources\HealthVisits\Schemas\HealthVisitForm;
use App\Filament\Resources\HealthVisits\Schemas\HealthVisitInfolist;
use App\Filament\Resources\HealthVisits\Tables\HealthVisitsTable;
use App\Models\HealthVisit;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Kunjungan kesehatan santri (F031). Catat, ubah, dan hapus lewat `App\Actions\Kesehatan\*`.
 * Cakupan jenjang dan cakupan musyrif dari global scope `HealthVisit`; tanpa hapus massal.
 */
class HealthVisitResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = HealthVisit::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?int $navigationSort = 1;

    public static function getModule(): string
    {
        return 'kesehatan';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.health_visit.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.health_visit.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.kesehatan');
    }

    public static function form(Schema $schema): Schema
    {
        return HealthVisitForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return HealthVisitInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HealthVisitsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHealthVisits::route('/'),
            'create' => CreateHealthVisit::route('/create'),
            'edit' => EditHealthVisit::route('/{record}/edit'),
        ];
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->label(__('pondok.health_visit.delete_action'))
            ->modalHeading(__('pondok.health_visit.delete_action'))
            ->modalDescription(__('pondok.health_visit.delete_description'))
            ->authorize(fn (HealthVisit $record): bool => static::canDelete($record))
            ->successNotificationTitle(__('pondok.health_visit.deleted'))
            ->using(function (HealthVisit $record, DeleteHealthVisit $deleteHealthVisit): bool {
                $deleteHealthVisit->handle($record, static::user());

                return true;
            });
    }

    public static function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
