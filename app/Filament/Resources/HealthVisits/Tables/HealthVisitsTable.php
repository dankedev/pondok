<?php

namespace App\Filament\Resources\HealthVisits\Tables;

use App\Filament\Resources\HealthVisits\HealthVisitResource;
use App\Models\HealthVisit;
use App\Models\Student;
use App\Models\Unit;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HealthVisitsTable
{
    /** Panjang keluhan yang tampil di tabel; isi lengkap di form/modal lihat. */
    public const COMPLAINT_LIMIT = 60;

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('visited_at')->orderByDesc('id'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['student.unit', 'recorder']))
            ->columns([
                static::visitedAtColumn(),
                TextColumn::make('student.name')
                    ->label(__('pondok.health_visit.student'))
                    ->description(fn (HealthVisit $record): ?string => $record->student?->nis)
                    ->searchable(['name', 'nis']),
                TextColumn::make('student.unit.name')
                    ->label(__('pondok.health_visit.unit')),
                ...static::detailColumns(),
            ])
            ->filters([
                Filter::make('visited_at')
                    ->schema([
                        DatePicker::make('from')->label(__('pondok.health_visit.filter_from')),
                        DatePicker::make('until')->label(__('pondok.health_visit.filter_until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('visited_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('visited_at', '<=', $date))),
                SelectFilter::make('unit')
                    ->label(__('pondok.health_visit.unit'))
                    ->options(fn (): array => Unit::query()->accessible()->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query : $query->whereIn(
                        'student_id',
                        Student::withoutUnitScope()->withTrashed()->where('unit_id', (int) $data['value'])->select('id'),
                    )),
                TernaryFilter::make('needs_referral')
                    ->label(__('pondok.health_visit.referral_filter'))
                    ->trueLabel(__('pondok.health_visit.referral_yes'))
                    ->falseLabel(__('pondok.health_visit.referral_no')),
            ])
            ->emptyStateIcon(Heroicon::OutlinedHeart)
            ->emptyStateHeading(__('pondok.health_visit.empty.heading'))
            ->emptyStateDescription(__('pondok.health_visit.empty.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('pondok.health_visit.create_action'))
                    ->url(fn (): string => HealthVisitResource::getUrl('create')),
            ])
            ->recordActions([
                EditAction::make()
                    ->authorize(fn (HealthVisit $record): bool => HealthVisitResource::canEdit($record)),
                ViewAction::make()
                    ->hidden(fn (HealthVisit $record): bool => HealthVisitResource::canEdit($record)),
                ActionGroup::make([
                    HealthVisitResource::deleteAction(),
                ]),
            ]);
    }

    public static function visitedAtColumn(): TextColumn
    {
        return TextColumn::make('visited_at')
            ->label(__('pondok.health_visit.fields.visited_at'))
            ->dateTime('j M Y, H.i')
            ->sortable();
    }

    /**
     * Kolom keluhan, tanda, dan petugas; dipakai juga tab kesehatan di halaman santri.
     *
     * @return list<TextColumn>
     */
    public static function detailColumns(): array
    {
        return [
            TextColumn::make('complaint')
                ->label(__('pondok.health_visit.fields.complaint'))
                ->limit(self::COMPLAINT_LIMIT),
            TextColumn::make('flags')
                ->label(__('pondok.health_visit.flags'))
                ->state(fn (HealthVisit $record): array => static::flags($record))
                ->badge()
                ->color('warning')
                ->placeholder('-'),
            TextColumn::make('recorder.name')
                ->label(__('pondok.health_visit.recorded_by'))
                ->placeholder('-'),
        ];
    }

    /**
     * Label penanda yang bernilai true ("Perlu rujukan", "Perlu izin pulang").
     *
     * @return list<string>
     */
    public static function flags(HealthVisit $record): array
    {
        return array_values(array_filter([
            $record->needs_referral ? __('pondok.health_visit.badges.needs_referral') : null,
            $record->needs_leave ? __('pondok.health_visit.badges.needs_leave') : null,
        ]));
    }
}
