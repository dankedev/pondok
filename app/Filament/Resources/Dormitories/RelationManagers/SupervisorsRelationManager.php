<?php

namespace App\Filament\Resources\Dormitories\RelationManagers;

use App\Actions\Asrama\AssignSupervisor;
use App\Actions\Asrama\EndSupervision;
use App\Models\AcademicYear;
use App\Models\Dormitory;
use App\Models\DormitorySupervisor;
use App\Models\Room;
use App\Models\Staff;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Kepala asrama dan musyrif per tahun ajaran. Riwayat tidak diedit atau
 * dihapus; tugas diakhiri lewat Action `EndSupervision`.
 */
class SupervisorsRelationManager extends RelationManager
{
    protected static string $relationship = 'supervisors';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('pondok.dormitory_supervisor.plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['staff', 'room', 'academicYear']))
            ->defaultSort(fn (Builder $query) => $query->orderByRaw('CASE WHEN end_on IS NULL THEN 0 ELSE 1 END')->orderByDesc('start_on'))
            ->columns([
                TextColumn::make('staff.name')
                    ->label(__('pondok.dormitory_supervisor.staff'))
                    ->searchable(),
                TextColumn::make('role')
                    ->label(__('pondok.dormitory_supervisor.role'))
                    ->formatStateUsing(fn (string $state): string => __("pondok.dormitory_supervisor.roles.{$state}")),
                TextColumn::make('room.name')
                    ->label(__('pondok.dormitory_supervisor.room'))
                    ->placeholder(__('pondok.dormitory_supervisor.whole_dormitory')),
                TextColumn::make('academicYear.name')
                    ->label(__('pondok.common.academic_year')),
                TextColumn::make('start_on')
                    ->label(__('pondok.dormitory_supervisor.start_on'))
                    ->date('j M Y'),
                TextColumn::make('end_on')
                    ->label(__('pondok.dormitory_supervisor.end_on'))
                    ->date('j M Y')
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('academic_year_id')
                    ->label(__('pondok.common.academic_year'))
                    ->options(fn (): array => AcademicYear::query()->orderByDesc('starts_on')->pluck('name', 'id')->all())
                    ->default(fn (): ?int => AcademicYear::current()?->id),
            ])
            ->emptyStateHeading(__('pondok.dormitory_supervisor.empty'))
            ->headerActions([
                $this->assignSupervisorAction(),
            ])
            ->recordActions([
                $this->endSupervisionAction(),
            ]);
    }

    private function assignSupervisorAction(): Action
    {
        return Action::make('assignSupervisor')
            ->label(__('pondok.dormitory_supervisor.assign'))
            ->icon(Heroicon::OutlinedPlus)
            ->authorize(fn (): bool => auth()->user()->can('create:dormitory-supervisor'))
            ->schema([
                Select::make('staff_id')
                    ->label(__('pondok.dormitory_supervisor.staff'))
                    ->options(fn (): array => Staff::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                Select::make('role')
                    ->label(__('pondok.dormitory_supervisor.role'))
                    ->options(__('pondok.dormitory_supervisor.roles'))
                    ->default(DormitorySupervisor::ROLE_SUPERVISOR)
                    ->required()
                    ->live(),
                Select::make('room_id')
                    ->label(__('pondok.dormitory_supervisor.room'))
                    ->helperText(__('pondok.dormitory_supervisor.room_helper'))
                    ->options(fn (): array => $this->dormitory()->rooms()->orderBy('name')->pluck('name', 'id')->all())
                    ->hidden(fn (Get $get): bool => $get('role') === DormitorySupervisor::ROLE_HEAD),
                Select::make('academic_year_id')
                    ->label(__('pondok.common.academic_year'))
                    ->options(fn (): array => AcademicYear::query()->orderByDesc('starts_on')->pluck('name', 'id')->all())
                    ->default(fn (): ?int => AcademicYear::current()?->id)
                    ->required(),
                DatePicker::make('start_on')
                    ->label(__('pondok.dormitory_supervisor.start_on'))
                    ->default(fn (): string => today()->toDateString())
                    ->required(),
            ])
            ->action(function (array $data, AssignSupervisor $assign, Action $action): void {
                $staff = Staff::query()->findOrFail($data['staff_id']);
                $room = filled($data['room_id'] ?? null) ? Room::query()->findOrFail($data['room_id']) : null;

                try {
                    $supervisor = $assign->handle(
                        $this->dormitory(),
                        $staff,
                        $data['role'],
                        AcademicYear::query()->findOrFail($data['academic_year_id']),
                        $room,
                        Carbon::parse($data['start_on']),
                    );
                } catch (ValidationException $exception) {
                    Notification::make()->title($exception->validator->errors()->first())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title(__('pondok.dormitory_supervisor.assigned', [
                        'name' => $staff->name,
                        'role' => __("pondok.dormitory_supervisor.roles.{$supervisor->role}"),
                    ]))
                    ->success()
                    ->send();
            });
    }

    private function endSupervisionAction(): Action
    {
        return Action::make('endSupervision')
            ->label(__('pondok.dormitory_supervisor.end'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('pondok.dormitory_supervisor.end_description'))
            ->authorize(fn (): bool => auth()->user()->can('update:dormitory-supervisor'))
            ->hidden(fn (DormitorySupervisor $record): bool => $record->end_on !== null)
            ->schema([
                DatePicker::make('end_on')
                    ->label(__('pondok.dormitory_supervisor.end_on'))
                    ->default(fn (): string => today()->toDateString())
                    ->required(),
            ])
            ->action(function (DormitorySupervisor $record, array $data, EndSupervision $end, Action $action): void {
                try {
                    $end->handle($record, Carbon::parse($data['end_on']));
                } catch (ValidationException $exception) {
                    Notification::make()->title($exception->validator->errors()->first())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title(__('pondok.dormitory_supervisor.ended', ['name' => $record->staff->name]))
                    ->success()
                    ->send();
            });
    }

    private function dormitory(): Dormitory
    {
        /** @var Dormitory */
        return $this->getOwnerRecord();
    }
}
