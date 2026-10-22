<?php

namespace App\Filament\Resources\HealthVisits\Schemas;

use App\Filament\Resources\HealthVisits\Tables\HealthVisitsTable;
use App\Models\HealthVisit;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/**
 * Detail kunjungan (modal lihat) untuk user tanpa izin ubah, mis. musyrif.
 */
class HealthVisitInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextEntry::make('student.name')
                    ->label(__('pondok.health_visit.fields.student_id'))
                    ->helperText(fn (HealthVisit $record): ?string => $record->student?->nis),
                TextEntry::make('visited_at')
                    ->label(__('pondok.health_visit.fields.visited_at'))
                    ->dateTime('j M Y, H.i'),
                TextEntry::make('complaint')
                    ->label(__('pondok.health_visit.fields.complaint'))
                    ->columnSpanFull(),
                TextEntry::make('treatment')
                    ->label(__('pondok.health_visit.fields.treatment'))
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('flags')
                    ->label(__('pondok.health_visit.flags'))
                    ->state(fn (HealthVisit $record): array => HealthVisitsTable::flags($record))
                    ->badge()
                    ->color('warning')
                    ->placeholder('-'),
                TextEntry::make('recorder.name')
                    ->label(__('pondok.health_visit.recorded_by'))
                    ->placeholder('-'),
                TextEntry::make('note')
                    ->label(__('pondok.health_visit.fields.note'))
                    ->placeholder('-')
                    ->columnSpanFull(),
            ]);
    }
}
