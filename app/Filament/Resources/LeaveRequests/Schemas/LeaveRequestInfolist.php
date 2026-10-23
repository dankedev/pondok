<?php

namespace App\Filament\Resources\LeaveRequests\Schemas;

use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveRequestVia;
use App\Filament\Resources\LeaveRequests\Tables\LeaveRequestsTable;
use App\Models\LeaveRequest;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Detail izin di modal lihat: permintaan, jadwal, lalu jejak keputusan dan pos.
 */
class LeaveRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('pondok.leave_request.section_request'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('student.name')
                            ->label(__('pondok.leave_request.fields.student_id'))
                            ->helperText(fn (LeaveRequest $record): ?string => $record->student?->nis),
                        TextEntry::make('leaveType.name')
                            ->label(__('pondok.leave_request.fields.leave_type_id')),
                        TextEntry::make('status')
                            ->label(__('pondok.leave_request.status'))
                            ->badge()
                            ->state(fn (LeaveRequest $record): array => LeaveRequestsTable::statusBadges($record))
                            ->color(fn (string $state, LeaveRequest $record): string => LeaveRequestsTable::statusBadgeColor($state, $record)),
                        TextEntry::make('requested_via')
                            ->label(__('pondok.leave_request.fields.requested_via'))
                            ->formatStateUsing(fn (LeaveRequestVia $state): string => $state->label()),
                        TextEntry::make('reason')
                            ->label(__('pondok.leave_request.fields.reason'))
                            ->columnSpanFull(),
                    ]),
                Section::make(__('pondok.leave_request.section_schedule'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('planned_departure')
                            ->label(__('pondok.leave_request.fields.planned_departure'))
                            ->dateTime(LeaveRequestsTable::DATE_TIME_FORMAT),
                        TextEntry::make('return_due')
                            ->label(__('pondok.leave_request.fields.return_due'))
                            ->dateTime(LeaveRequestsTable::DATE_TIME_FORMAT),
                        TextEntry::make('pickup_name')
                            ->label(__('pondok.leave_request.fields.pickup_name'))
                            ->placeholder('-'),
                        TextEntry::make('pickup_phone')
                            ->label(__('pondok.leave_request.fields.pickup_phone'))
                            ->placeholder('-'),
                    ]),
                Section::make(__('pondok.leave_request.section_trail'))
                    ->columns(2)
                    ->schema([
                        TextEntry::make('requester.name')
                            ->label(__('pondok.leave_request.requested_by'))
                            ->placeholder('-'),
                        TextEntry::make('approver.name')
                            ->label(fn (LeaveRequest $record): string => $record->status === LeaveRequestStatus::Rejected
                                ? __('pondok.leave_request.rejected_by')
                                : __('pondok.leave_request.approved_by'))
                            ->helperText(fn (LeaveRequest $record): ?string => $record->approved_at?->translatedFormat(LeaveRequestsTable::DATE_TIME_FORMAT))
                            ->placeholder('-'),
                        TextEntry::make('departed_at')
                            ->label(__('pondok.leave_request.departed_at'))
                            ->dateTime(LeaveRequestsTable::DATE_TIME_FORMAT)
                            ->helperText(fn (LeaveRequest $record): ?string => $record->checkedOutBy?->name)
                            ->placeholder('-'),
                        TextEntry::make('returned_at')
                            ->label(__('pondok.leave_request.returned_at'))
                            ->dateTime(LeaveRequestsTable::DATE_TIME_FORMAT)
                            ->helperText(fn (LeaveRequest $record): ?string => $record->checkedInBy?->name)
                            ->placeholder('-'),
                        TextEntry::make('note')
                            ->label(__('pondok.leave_request.fields.note'))
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
