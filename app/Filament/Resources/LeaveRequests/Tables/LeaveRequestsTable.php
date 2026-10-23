<?php

namespace App\Filament\Resources\LeaveRequests\Tables;

use App\Actions\Perizinan\ResolveLeaveApprover;
use App\Enums\LeaveRequestStatus;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Models\Dormitory;
use App\Models\HomecomingPeriod;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Room;
use App\Models\RoomAssignment;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LeaveRequestsTable
{
    /** Tanggal + jam sesuai design-guide §5: `15 Okt 2026, 14.30`. */
    public const DATE_TIME_FORMAT = 'j M Y, H.i';

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('planned_departure')->orderByDesc('id'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['student.unit', 'student.activeRoom.dormitory', 'leaveType']))
            ->columns([
                TextColumn::make('student.name')
                    ->label(__('pondok.leave_request.fields.student_id'))
                    ->description(fn (LeaveRequest $record): ?string => $record->student?->nis)
                    ->searchable(['name', 'nis']),
                TextColumn::make('student.unit.name')
                    ->label(__('pondok.leave_request.unit'))
                    ->toggleable(),
                TextColumn::make('dormitory')
                    ->label(__('pondok.leave_request.dormitory'))
                    ->state(fn (LeaveRequest $record): ?string => $record->student?->activeRoom?->dormitory?->name)
                    ->description(fn (LeaveRequest $record): ?string => $record->student?->activeRoom?->name)
                    ->placeholder(__('pondok.leave_request.non_resident')),
                TextColumn::make('leaveType.name')
                    ->label(__('pondok.leave_request.fields.leave_type_id')),
                TextColumn::make('planned_departure')
                    ->label(__('pondok.leave_request.fields.planned_departure'))
                    ->dateTime(self::DATE_TIME_FORMAT)
                    ->sortable(),
                TextColumn::make('return_due')
                    ->label(__('pondok.leave_request.fields.return_due'))
                    ->dateTime(self::DATE_TIME_FORMAT)
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('pondok.leave_request.status'))
                    ->badge()
                    ->state(fn (LeaveRequest $record): array => static::statusBadges($record))
                    ->color(fn (string $state, LeaveRequest $record): string => static::statusBadgeColor($state, $record)),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('pondok.leave_request.status'))
                    ->options(collect(LeaveRequestStatus::cases())->mapWithKeys(fn (LeaveRequestStatus $status): array => [$status->value => $status->label()])->all()),
                Filter::make('late')
                    ->label(__('pondok.leave_request.late_filter'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->late()),
                Filter::make('pending_for_me')
                    ->label(__('pondok.leave_request.pending_for_me_filter'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereIn(
                        'leave_requests.id',
                        app(ResolveLeaveApprover::class)->pendingFor(LeaveRequestResource::user())->select('leave_requests.id'),
                    )),
                SelectFilter::make('dormitory')
                    ->label(__('pondok.leave_request.dormitory'))
                    ->options(fn (): array => Dormitory::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query : $query->whereIn(
                        'student_id',
                        RoomAssignment::withoutUnitScope()->active()
                            ->whereIn('room_id', Room::query()->where('dormitory_id', (int) $data['value'])->select('id'))
                            ->select('student_id'),
                    )),
                SelectFilter::make('leave_type_id')
                    ->label(__('pondok.leave_request.fields.leave_type_id'))
                    ->options(fn (): array => LeaveType::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('homecoming_period_id')
                    ->label(__('pondok.leave_request.homecoming_period'))
                    ->options(fn (): array => HomecomingPeriod::query()->orderByDesc('depart_on')->pluck('name', 'id')->all()),
            ])
            ->emptyStateIcon(Heroicon::OutlinedArrowRightStartOnRectangle)
            ->emptyStateHeading(__('pondok.leave_request.empty.heading'))
            ->emptyStateDescription(__('pondok.leave_request.empty.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('pondok.leave_request.create_action'))
                    ->url(fn (): string => LeaveRequestResource::getUrl('create')),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    LeaveRequestResource::approveAction(),
                    LeaveRequestResource::rejectAction(),
                    LeaveRequestResource::checkOutAction(),
                    LeaveRequestResource::checkInAction(),
                    LeaveRequestResource::cancelAction(),
                ]),
            ]);
    }

    /**
     * Label badge kolom status: status izin, ditambah "Terlambat" bila `is_late` (dihitung, bukan status).
     *
     * @return list<string>
     */
    public static function statusBadges(LeaveRequest $record): array
    {
        return array_values(array_filter([
            $record->status->label(),
            $record->is_late ? __('pondok.leave_request.late') : null,
        ]));
    }

    public static function statusBadgeColor(string $state, LeaveRequest $record): string
    {
        return $state === __('pondok.leave_request.late') ? 'danger' : $record->status->color();
    }
}
