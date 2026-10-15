<?php

namespace App\Filament\Resources\Students;

use App\Actions\Akademik\ChangeStudentUnit;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Resources\Documents\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Students\Pages\CreateStudent;
use App\Filament\Resources\Students\Pages\EditStudent;
use App\Filament\Resources\Students\Pages\ListStudents;
use App\Filament\Resources\Students\RelationManagers\EnrollmentsRelationManager;
use App\Filament\Resources\Students\RelationManagers\GuardiansRelationManager;
use App\Filament\Resources\Students\Schemas\StudentForm;
use App\Filament\Resources\Students\Tables\StudentsTable;
use App\Models\Cohort;
use App\Models\Student;
use App\Models\Unit;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\ValidationException;

class StudentResource extends Resource
{
    use BelongsToModule;

    protected static ?string $model = Student::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function getModule(): string
    {
        return 'akademik';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.student.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.student.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.akademik');
    }

    public static function form(Schema $schema): Schema
    {
        return StudentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StudentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        // Dokumen di tab pertama: ringkasan kelengkapan berkas wajib langsung terlihat saat membuka santri.
        return [
            DocumentsRelationManager::class,
            EnrollmentsRelationManager::class,
            GuardiansRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudents::route('/'),
            'create' => CreateStudent::route('/create'),
            'edit' => EditStudent::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    /**
     * Pilihan angkatan untuk jenjang yang dipilih di form.
     *
     * @return array<int, string>
     */
    public static function cohortOptions(mixed $unitId): array
    {
        if (blank($unitId)) {
            return [];
        }

        return Cohort::query()
            ->where('unit_id', $unitId)
            ->orderByDesc('entry_year')
            ->pluck('name', 'id')
            ->all();
    }

    public static function changeUnitAction(): Action
    {
        return Action::make('changeUnit')
            ->label(__('pondok.student.change_unit'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->authorize(fn (Student $record): bool => static::canEdit($record))
            ->hidden(fn (Student $record): bool => $record->trashed())
            ->fillForm(fn (Student $record): array => ['unit_id' => $record->unit_id, 'cohort_id' => $record->cohort_id])
            ->schema([
                Select::make('unit_id')
                    ->label(__('pondok.common.unit'))
                    ->options(fn (): array => Unit::query()->accessible()->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('cohort_id', null)),
                Select::make('cohort_id')
                    ->label(__('pondok.student.cohort'))
                    ->options(fn (Get $get): array => static::cohortOptions($get('unit_id')))
                    ->required(),
            ])
            ->action(function (Student $record, array $data, ChangeStudentUnit $changeUnit, Action $action): void {
                $unit = Unit::query()->accessible()->findOrFail($data['unit_id']);

                try {
                    $changeUnit->handle($record, $unit, Cohort::query()->findOrFail($data['cohort_id']));
                } catch (ValidationException $exception) {
                    Notification::make()->title($exception->validator->errors()->first())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title(__('pondok.student.unit_changed', ['name' => $record->name, 'unit' => $unit->name]))
                    ->success()
                    ->send();
            });
    }
}
