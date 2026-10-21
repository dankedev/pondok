<?php

namespace App\Filament\Resources\RoomAssignments;

use App\Actions\Asrama\AssignRoom;
use App\Actions\Asrama\VacateRoom;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Resources\RoomAssignments\Pages\ManageRoomAssignments;
use App\Models\Dormitory;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Student;
use App\Models\Unit;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Penghuni asrama. Penempatan, pindah kamar, dan keluar asrama selalu lewat
 * Action sehingga riwayat tersimpan sebagai baris baru.
 */
class RoomAssignmentResource extends Resource
{
    use BelongsToModule;

    public const STATUS_ACTIVE = 'aktif';

    public const STATUS_HISTORY = 'riwayat';

    protected static ?string $model = RoomAssignment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static ?int $navigationSort = 1;

    public static function getModule(): string
    {
        return 'asrama';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.room_assignment.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.room_assignment.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.asrama');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['student.unit', 'room.dormitory']))
            ->defaultSort(fn (Builder $query) => $query
                ->orderBy(Room::query()
                    ->select('dormitories.name')
                    ->join('dormitories', 'dormitories.id', '=', 'rooms.dormitory_id')
                    ->whereColumn('rooms.id', 'room_assignments.room_id'))
                ->orderBy(Room::query()->select('name')->whereColumn('rooms.id', 'room_assignments.room_id'))
                ->orderBy(Student::withoutUnitScope()->withTrashed()->select('name')->whereColumn('students.id', 'room_assignments.student_id')))
            ->columns([
                TextColumn::make('student.nis')
                    ->label(__('pondok.student.nis'))
                    ->searchable(),
                TextColumn::make('student.name')
                    ->label(__('pondok.common.name'))
                    ->searchable(),
                TextColumn::make('student.unit.name')
                    ->label(__('pondok.common.unit')),
                TextColumn::make('room.dormitory.name')
                    ->label(__('pondok.room_assignment.dormitory')),
                TextColumn::make('room.name')
                    ->label(__('pondok.room_assignment.room')),
                TextColumn::make('start_on')
                    ->label(__('pondok.room_assignment.start_on'))
                    ->date('j M Y'),
                TextColumn::make('end_on')
                    ->label(__('pondok.room_assignment.end_on'))
                    ->date('j M Y')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('note')
                    ->label(__('pondok.room_assignment.note'))
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('pondok.room_assignment.status'))
                    ->options(__('pondok.room_assignment.statuses'))
                    ->default(self::STATUS_ACTIVE)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        self::STATUS_ACTIVE => $query->whereNull('end_on'),
                        self::STATUS_HISTORY => $query->whereNotNull('end_on'),
                        default => $query,
                    }),
                SelectFilter::make('dormitory')
                    ->label(__('pondok.room_assignment.dormitory'))
                    ->options(fn (): array => Dormitory::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereIn('room_id', Room::query()->where('dormitory_id', $data['value'])->select('id'))
                        : $query),
                SelectFilter::make('unit')
                    ->label(__('pondok.common.unit'))
                    ->options(fn (): array => Unit::query()->accessible()->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereIn('student_id', Student::withoutUnitScope()->withTrashed()->where('unit_id', $data['value'])->select('id'))
                        : $query),
            ])
            ->emptyStateHeading(__('pondok.room_assignment.empty'))
            ->emptyStateIcon(Heroicon::OutlinedHomeModern)
            ->recordActions([
                ActionGroup::make([
                    self::moveAction(),
                    self::vacateAction(),
                ]),
            ]);
    }

    public static function assignAction(): Action
    {
        return Action::make('assign')
            ->label(__('pondok.room_assignment.assign'))
            ->icon(Heroicon::OutlinedPlus)
            ->authorize(fn (): bool => static::canCreate())
            ->schema([
                Select::make('student_id')
                    ->label(__('pondok.room_assignment.student'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => static::placeableStudents()
                        ->where(fn (Builder $query) => $query->where('name', 'like', "%{$search}%")->orWhere('nis', 'like', "%{$search}%"))
                        ->orderBy('name')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (Student $student): array => [$student->id => "{$student->nis} - {$student->name}"])
                        ->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => ($student = static::placeableStudents()->find($value)) ? "{$student->nis} - {$student->name}" : null)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('dormitory_id', null)),
                ...static::roomFields(fn (Get $get): ?string => Student::query()->find($get('student_id'))?->gender),
                DatePicker::make('start_on')
                    ->label(__('pondok.room_assignment.start_on'))
                    ->default(fn (): string => today()->toDateString())
                    ->required(),
                Textarea::make('note')
                    ->label(__('pondok.room_assignment.note'))
                    ->maxLength(255),
            ])
            ->action(function (array $data, AssignRoom $assign, Action $action): void {
                $student = static::placeableStudents()->findOrFail($data['student_id']);
                $room = Room::query()->findOrFail($data['room_id']);

                static::runAction($action, fn () => $assign->handle($student, $room, Carbon::parse($data['start_on']), $data['note'] ?? null));

                Notification::make()
                    ->title(__('pondok.room_assignment.assigned', ['name' => $student->name, 'room' => $room->name]))
                    ->success()
                    ->send();
            });
    }

    public static function moveAction(): Action
    {
        return Action::make('move')
            ->label(__('pondok.room_assignment.move'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->authorize(fn (RoomAssignment $record): bool => static::canEdit($record))
            ->hidden(fn (RoomAssignment $record): bool => $record->end_on !== null)
            ->schema(fn (RoomAssignment $record): array => [
                ...static::roomFields(fn (): ?string => $record->student?->gender, $record->room_id),
                DatePicker::make('start_on')
                    ->label(__('pondok.room_assignment.move_on'))
                    ->default(fn (): string => today()->toDateString())
                    ->required(),
                Textarea::make('note')
                    ->label(__('pondok.room_assignment.move_note'))
                    ->maxLength(255),
            ])
            ->action(function (RoomAssignment $record, array $data, AssignRoom $assign, Action $action): void {
                $room = Room::query()->findOrFail($data['room_id']);

                static::runAction($action, fn () => $assign->handle($record->student, $room, Carbon::parse($data['start_on']), $data['note'] ?? null));

                Notification::make()
                    ->title(__('pondok.room_assignment.moved', ['name' => $record->student->name, 'room' => $room->name]))
                    ->success()
                    ->send();
            });
    }

    public static function vacateAction(): Action
    {
        return Action::make('vacate')
            ->label(__('pondok.room_assignment.vacate'))
            ->icon(Heroicon::OutlinedStopCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('pondok.room_assignment.vacate_description'))
            ->authorize(fn (RoomAssignment $record): bool => static::canEdit($record))
            ->hidden(fn (RoomAssignment $record): bool => $record->end_on !== null)
            ->schema([
                DatePicker::make('end_on')
                    ->label(__('pondok.room_assignment.end_on'))
                    ->default(fn (): string => today()->toDateString())
                    ->required(),
                Textarea::make('note')
                    ->label(__('pondok.room_assignment.vacate_note'))
                    ->maxLength(255),
            ])
            ->action(function (RoomAssignment $record, array $data, VacateRoom $vacate, Action $action): void {
                static::runAction($action, fn () => $vacate->handle($record->student, Carbon::parse($data['end_on']), $data['note'] ?? null));

                Notification::make()
                    ->title(__('pondok.room_assignment.vacated', ['name' => $record->student->name]))
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRoomAssignments::route('/'),
        ];
    }

    /**
     * Santri aktif tanpa penempatan aktif, sudah tersaring cakupan jenjang.
     */
    protected static function placeableStudents(): Builder
    {
        return Student::query()->active()->nonResident();
    }

    /**
     * Pilihan asrama (aktif, sesuai gender santri) dan kamar aktif di asrama itu.
     *
     * @param  \Closure(Get): ?string  $gender
     * @return list<Select>
     */
    protected static function roomFields(\Closure $gender, ?int $excludeRoomId = null): array
    {
        return [
            Select::make('dormitory_id')
                ->label(__('pondok.room_assignment.dormitory'))
                ->options(fn (Get $get): array => Dormitory::query()
                    ->where('is_active', true)
                    ->when($gender($get), fn (Builder $query, string $value) => $query->where('gender', $value))
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('room_id', null)),
            Select::make('room_id')
                ->label(__('pondok.room_assignment.room'))
                ->options(fn (Get $get): array => Room::query()
                    ->where('dormitory_id', $get('dormitory_id'))
                    ->where('is_active', true)
                    ->when($excludeRoomId, fn (Builder $query, int $id) => $query->whereKeyNot($id))
                    ->orderBy('name')
                    ->get()
                    ->mapWithKeys(fn (Room $room): array => [$room->id => __('pondok.room.remaining', ['name' => $room->name, 'count' => $room->remainingCapacity()])])
                    ->all())
                ->required(),
        ];
    }

    /**
     * Jalankan Action; kegagalan validasi tampil sebagai notifikasi lalu modal tetap terbuka.
     */
    protected static function runAction(Action $action, \Closure $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->validator->errors()->first())->danger()->send();
            $action->halt();
        }
    }
}
