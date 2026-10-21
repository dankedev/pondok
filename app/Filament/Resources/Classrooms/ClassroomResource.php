<?php

namespace App\Filament\Resources\Classrooms;

use App\Actions\Akademik\PromoteStudents;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Resources\Classrooms\Pages\ManageClassrooms;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Unit;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;

class ClassroomResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Classroom::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    public static function getModule(): string
    {
        return 'akademik';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.classroom.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.classroom.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.akademik');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('unit_id', Unit::query()->accessible()->select('id'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('academic_year_id')
                    ->label(__('pondok.common.academic_year'))
                    ->relationship('academicYear', 'name', fn (Builder $query) => $query->orderByDesc('starts_on'))
                    ->default(fn (): ?int => AcademicYear::current()?->id)
                    ->required()
                    ->live(),
                Select::make('unit_id')
                    ->label(__('pondok.common.unit'))
                    ->relationship('unit', 'name', fn (Builder $query) => $query->accessible()->orderBy('sort_order'))
                    ->required()
                    ->live(),
                TextInput::make('grade')
                    ->label(__('pondok.classroom.grade'))
                    ->integer()
                    ->minValue(1)
                    ->maxValue(12)
                    ->required(),
                TextInput::make('name')
                    ->label(__('pondok.classroom.name'))
                    ->helperText(__('pondok.classroom.name_helper'))
                    ->required()
                    ->maxLength(50)
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                            ->where('academic_year_id', $get('academic_year_id'))
                            ->where('unit_id', $get('unit_id')),
                    )
                    ->validationMessages(['unique' => __('pondok.classroom.unique')]),
                Select::make('gender')
                    ->label(__('pondok.classroom.gender'))
                    ->options(__('pondok.classroom.genders'))
                    ->default(Classroom::GENDER_MIXED)
                    ->required(),
                Select::make('homeroom_staff_id')
                    ->label(__('pondok.classroom.homeroom'))
                    ->relationship('homeroomStaff', 'name', fn (Builder $query) => $query->where('is_active', true))
                    ->searchable()
                    ->preload(),
                TextInput::make('capacity')
                    ->label(__('pondok.classroom.capacity'))
                    ->helperText(__('pondok.classroom.capacity_helper'))
                    ->integer()
                    ->minValue(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => $query->orderBy('unit_id')->orderBy('grade')->orderBy('name'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['academicYear', 'unit', 'homeroomStaff'])->withCount('enrollments'))
            ->columns([
                TextColumn::make('academicYear.name')
                    ->label(__('pondok.common.academic_year')),
                TextColumn::make('unit.name')
                    ->label(__('pondok.common.unit')),
                TextColumn::make('grade')
                    ->label(__('pondok.classroom.grade'))
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('pondok.classroom.name'))
                    ->searchable(),
                TextColumn::make('gender')
                    ->label(__('pondok.classroom.gender'))
                    ->formatStateUsing(fn (string $state): string => __("pondok.classroom.genders.{$state}")),
                TextColumn::make('homeroomStaff.name')
                    ->label(__('pondok.classroom.homeroom'))
                    ->placeholder('-'),
                TextColumn::make('enrollments_count')
                    ->label(__('pondok.classroom.students_count'))
                    ->formatStateUsing(fn (int $state, Classroom $record): string => $record->capacity === null ? (string) $state : "{$state} / {$record->capacity}"),
            ])
            ->filters([
                SelectFilter::make('academic_year_id')
                    ->label(__('pondok.common.academic_year'))
                    ->relationship('academicYear', 'name')
                    ->default(fn (): ?int => AcademicYear::current()?->id),
                SelectFilter::make('unit_id')
                    ->label(__('pondok.common.unit'))
                    ->relationship('unit', 'name', fn (Builder $query) => $query->accessible()),
                SelectFilter::make('grade')
                    ->label(__('pondok.classroom.grade'))
                    ->options(array_combine(range(1, 12), range(1, 12))),
            ])
            ->recordActions([
                self::promoteAction(),
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (Classroom $record): bool => $record->enrollments_count > 0),
            ]);
    }

    public static function promoteAction(): Action
    {
        return Action::make('promote')
            ->label(__('pondok.classroom.promote'))
            ->icon(Heroicon::OutlinedArrowUpCircle)
            ->modalDescription(__('pondok.classroom.promote_helper'))
            ->authorize(fn (): bool => auth()->user()->can('update:student'))
            ->schema(fn (Classroom $record): array => [
                Select::make('target_classroom_id')
                    ->label(__('pondok.classroom.promote_target'))
                    ->options(fn (): array => Classroom::query()
                        ->with('academicYear')
                        ->where('unit_id', $record->unit_id)
                        ->whereHas('academicYear', fn (Builder $query) => $query->where('starts_on', '>', $record->academicYear->starts_on))
                        ->orderBy('grade')
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (Classroom $classroom): array => [$classroom->id => "{$classroom->name} ({$classroom->academicYear->name})"])
                        ->all())
                    ->required(),
            ])
            ->action(function (Classroom $record, array $data, PromoteStudents $promote, Action $action): void {
                $target = Classroom::query()->findOrFail($data['target_classroom_id']);

                try {
                    $result = $promote->handle($record, $target);
                } catch (ValidationException $exception) {
                    Notification::make()->title($exception->validator->errors()->first())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title(__('pondok.classroom.promoted', ['count' => $result['promoted'], 'classroom' => $target->name]))
                    ->body($result['skipped'] === [] ? null : __('pondok.classroom.promote_skipped', ['count' => count($result['skipped'])]).': '.implode(' ', $result['skipped']))
                    ->status($result['skipped'] === [] ? 'success' : 'warning')
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageClassrooms::route('/'),
        ];
    }
}
