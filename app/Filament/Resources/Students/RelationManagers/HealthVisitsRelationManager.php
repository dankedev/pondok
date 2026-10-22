<?php

namespace App\Filament\Resources\Students\RelationManagers;

use App\Filament\Resources\HealthVisits\Tables\HealthVisitsTable;
use App\Models\HealthVisit;
use App\Models\Setting;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Tab riwayat kesehatan di halaman santri (F031), hanya baca. Kunjungan dikelola di `HealthVisitResource`.
 */
class HealthVisitsRelationManager extends RelationManager
{
    protected static string $relationship = 'healthVisits';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('pondok.health_visit.student_tab.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Setting::moduleEnabled('kesehatan') && Gate::allows('viewAny', HealthVisit::class);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('visited_at')->orderByDesc('id'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with('recorder'))
            ->columns([
                HealthVisitsTable::visitedAtColumn(),
                ...HealthVisitsTable::detailColumns(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedHeart)
            ->emptyStateHeading(__('pondok.health_visit.student_tab.empty'));
    }
}
