<?php

namespace App\Filament\Resources\StudentViolations\Schemas;

use App\Enums\ViolationCategory;
use App\Models\StudentViolation;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Detail pelanggaran, termasuk alasan dan pembatal bila catatan dibatalkan.
 */
class StudentViolationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('pondok.kedisiplinan.violation.section_record'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('student.name')
                            ->label(__('pondok.kedisiplinan.fields.student_id'))
                            ->helperText(fn (StudentViolation $record): ?string => $record->student?->nis),
                        TextEntry::make('occurred_on')
                            ->label(__('pondok.kedisiplinan.fields.occurred_on'))
                            ->date('j M Y'),
                        TextEntry::make('violationType.name')
                            ->label(__('pondok.kedisiplinan.fields.violation_type_id')),
                        TextEntry::make('violationType.category')
                            ->label(__('pondok.kedisiplinan.fields.category'))
                            ->badge()
                            ->formatStateUsing(fn (ViolationCategory $state): string => $state->label())
                            ->color(fn (ViolationCategory $state): string => $state->color()),
                        TextEntry::make('points')
                            ->label(__('pondok.kedisiplinan.fields.points'))
                            ->numeric(),
                        TextEntry::make('academicYear.name')
                            ->label(__('pondok.kedisiplinan.violation.academic_year')),
                        TextEntry::make('recorder.name')
                            ->label(__('pondok.kedisiplinan.violation.recorded_by'))
                            ->placeholder('-'),
                        TextEntry::make('description')
                            ->label(__('pondok.kedisiplinan.fields.description'))
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),
                Section::make(__('pondok.kedisiplinan.violation.section_cancel'))
                    ->columns(2)
                    ->visible(fn (StudentViolation $record): bool => $record->isCancelled())
                    ->schema([
                        TextEntry::make('cancelled_at')
                            ->label(__('pondok.kedisiplinan.violation.cancelled_at'))
                            ->dateTime('j M Y, H.i'),
                        TextEntry::make('canceller.name')
                            ->label(__('pondok.kedisiplinan.violation.cancelled_by'))
                            ->placeholder('-'),
                        TextEntry::make('cancel_reason')
                            ->label(__('pondok.kedisiplinan.fields.cancel_reason'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
