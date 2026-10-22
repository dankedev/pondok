<?php

namespace App\Filament\Resources\TahfidzExams;

use App\Actions\Tahfidz\DeleteTahfidzExam;
use App\Actions\Tahfidz\RecordTahfidzExam;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Concerns\TahfidzFormOptions;
use App\Filament\Resources\TahfidzExams\Pages\ManageTahfidzExams;
use App\Models\QuranSurah;
use App\Models\TahfidzExam;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ujian tahfidz (F023). Dicatat dan dihapus lewat Action; hasil lulus menandai juz `lulus_ujian`.
 */
class TahfidzExamResource extends Resource
{
    use BelongsToModule, ShowsActionErrorsOnForm, TahfidzFormOptions;

    protected static ?string $model = TahfidzExam::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?int $navigationSort = 3;

    public static function getModule(): string
    {
        return 'tahfidz';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.tahfidz.exam.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.tahfidz.exam.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.tahfidz');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['student', 'halaqah', 'examiner']))
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('exam_on')->orderByDesc('id'))
            ->columns([
                TextColumn::make('exam_on')
                    ->label(__('pondok.tahfidz.fields.exam_on'))
                    ->date('j M Y'),
                TextColumn::make('student.name')
                    ->label(__('pondok.tahfidz.fields.student_id'))
                    ->searchable(),
                TextColumn::make('halaqah.name')
                    ->label(__('pondok.tahfidz.fields.halaqah_id')),
                TextColumn::make('kind')
                    ->label(__('pondok.tahfidz.fields.kind'))
                    ->formatStateUsing(fn (string $state): string => __("pondok.tahfidz.exam.kinds.{$state}")),
                TextColumn::make('juz')
                    ->label(__('pondok.tahfidz.exam.juz'))
                    ->state(fn (TahfidzExam $record): string => $record->juzLabel()),
                TextColumn::make('score')
                    ->label(__('pondok.tahfidz.fields.score'))
                    ->numeric(),
                TextColumn::make('certificate_no')
                    ->label(__('pondok.tahfidz.fields.certificate_no'))
                    ->placeholder('-'),
                TextColumn::make('examiner.name')
                    ->label(__('pondok.tahfidz.fields.examiner_staff_id'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('result')
                    ->label(__('pondok.tahfidz.fields.result'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("pondok.tahfidz.exam.results.{$state}"))
                    ->color(fn (string $state): string => TahfidzExam::RESULT_COLORS[$state] ?? 'gray'),
            ])
            ->filters([
                SelectFilter::make('halaqah_id')
                    ->label(__('pondok.tahfidz.fields.halaqah_id'))
                    ->options(fn (): array => static::visibleHalaqahOptions()),
                SelectFilter::make('kind')
                    ->label(__('pondok.tahfidz.fields.kind'))
                    ->options(__('pondok.tahfidz.exam.kinds')),
                SelectFilter::make('result')
                    ->label(__('pondok.tahfidz.fields.result'))
                    ->options(__('pondok.tahfidz.exam.results')),
            ])
            ->emptyStateHeading(__('pondok.tahfidz.exam.empty'))
            ->emptyStateIcon(Heroicon::OutlinedAcademicCap)
            ->headerActions([
                static::createAction(),
            ])
            ->recordActions([
                static::deleteAction(),
            ]);
    }

    public static function createAction(): Action
    {
        $juz = fn (): array => array_combine(range(1, QuranSurah::LAST_JUZ), range(1, QuranSurah::LAST_JUZ));

        return Action::make('create')
            ->label(__('pondok.tahfidz.exam.create'))
            ->icon(Heroicon::OutlinedPlus)
            ->authorize(fn (): bool => static::canCreate())
            ->schema([
                Section::make()
                    ->columns(2)
                    ->schema([
                        ...static::halaqahAndStudentFields(),
                        Select::make('kind')
                            ->label(__('pondok.tahfidz.fields.kind'))
                            ->options(__('pondok.tahfidz.exam.kinds'))
                            ->default('kenaikan_juz')
                            ->required(),
                        DatePicker::make('exam_on')
                            ->label(__('pondok.tahfidz.fields.exam_on'))
                            ->default(fn (): string => today()->toDateString())
                            ->maxDate(today())
                            ->required(),
                        Select::make('juz_from')
                            ->label(__('pondok.tahfidz.fields.juz_from'))
                            ->options($juz)
                            ->required(),
                        Select::make('juz_to')
                            ->label(__('pondok.tahfidz.fields.juz_to'))
                            ->options($juz)
                            ->required(),
                        TextInput::make('score')
                            ->label(__('pondok.tahfidz.fields.score'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(100)
                            ->required(),
                        Select::make('result')
                            ->label(__('pondok.tahfidz.fields.result'))
                            ->options(__('pondok.tahfidz.exam.results'))
                            ->required()
                            ->live(),
                        TextInput::make('certificate_no')
                            ->label(__('pondok.tahfidz.fields.certificate_no'))
                            ->helperText(__('pondok.tahfidz.exam.certificate_helper'))
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => $get('result') === TahfidzExam::RESULT_PASSED),
                        static::staffField('examiner_staff_id'),
                    ]),
            ])
            ->action(function (array $data, RecordTahfidzExam $recordExam, Action $action): void {
                $saved = static::saveShowingFormErrors($action, fn () => $recordExam->handle(auth()->user(), $data));

                Notification::make()
                    ->title(__('pondok.tahfidz.exam.created', ['name' => $saved->student->name]))
                    ->success()
                    ->send();
            });
    }

    public static function deleteAction(): Action
    {
        return Action::make('delete')
            ->label(__('pondok.tahfidz.exam.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('pondok.tahfidz.exam.delete_description'))
            // Baris tabel sudah tersaring `visibleTo`; Action memeriksa ulang per record.
            ->authorize(fn (): bool => auth()->user()->can('delete:tahfidz-exam'))
            ->action(function (TahfidzExam $record, DeleteTahfidzExam $delete): void {
                $delete->handle(auth()->user(), $record);

                Notification::make()->title(__('pondok.tahfidz.exam.deleted'))->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTahfidzExams::route('/'),
        ];
    }
}
