<?php

namespace App\Filament\Resources\Visits\Tables;

use App\Filament\Resources\LeaveRequests\Tables\LeaveRequestsTable;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\Guardian;
use App\Models\Visit;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VisitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('started_at')->orderByDesc('id'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['student', 'recorder']))
            ->columns([
                TextColumn::make('started_at')
                    ->label(__('pondok.visit.fields.started_at'))
                    ->dateTime(LeaveRequestsTable::DATE_TIME_FORMAT)
                    ->sortable(),
                TextColumn::make('student.name')
                    ->label(__('pondok.visit.fields.student_id'))
                    ->description(fn (Visit $record): ?string => $record->student?->nis)
                    ->searchable(['name', 'nis']),
                TextColumn::make('visitor_name')
                    ->label(__('pondok.visit.fields.visitor_name'))
                    ->description(fn (Visit $record): string => static::relationLabel($record->relation))
                    ->searchable(),
                TextColumn::make('ended_at')
                    ->label(__('pondok.visit.fields.ended_at'))
                    ->dateTime(LeaveRequestsTable::DATE_TIME_FORMAT)
                    ->placeholder(__('pondok.visit.ongoing')),
                TextColumn::make('items_note')
                    ->label(__('pondok.visit.fields.items_note'))
                    ->limit(60)
                    ->placeholder('-'),
                TextColumn::make('recorder.name')
                    ->label(__('pondok.visit.recorded_by'))
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('started_at')
                    ->schema([
                        DatePicker::make('from')->label(__('pondok.visit.filter_from')),
                        DatePicker::make('until')->label(__('pondok.visit.filter_until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('started_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('started_at', '<=', $date))),
            ])
            ->emptyStateIcon(Heroicon::OutlinedHandRaised)
            ->emptyStateHeading(__('pondok.visit.empty.heading'))
            ->emptyStateDescription(__('pondok.visit.empty.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('pondok.visit.create_action'))
                    ->url(fn (): string => VisitResource::getUrl('create')),
            ])
            ->recordActions([
                EditAction::make()
                    ->label(__('pondok.visit.edit_action')),
            ]);
    }

    /**
     * Hubungan pengunjung: label baku bila sama dengan hubungan wali (`ayah`, `ibu`, `wali`), selain itu apa adanya.
     */
    public static function relationLabel(string $relation): string
    {
        return in_array($relation, Guardian::RELATIONS, true) ? __("pondok.guardian.relations.{$relation}") : $relation;
    }
}
