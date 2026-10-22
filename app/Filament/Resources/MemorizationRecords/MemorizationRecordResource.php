<?php

namespace App\Filament\Resources\MemorizationRecords;

use App\Actions\Tahfidz\DeleteMemorizationRecord;
use App\Actions\Tahfidz\RecordMemorization;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Concerns\TahfidzFormOptions;
use App\Filament\Resources\MemorizationRecords\Pages\ManageMemorizationRecords;
use App\Models\MemorizationRecord;
use App\Models\QuranSurah;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Setoran hafalan (F023). Dicatat dan dihapus lewat Action; tidak diubah (koreksi = hapus lalu
 * catat ulang). Daftar dan pilihan mengikuti `visibleTo` user.
 */
class MemorizationRecordResource extends Resource
{
    use BelongsToModule, ShowsActionErrorsOnForm, TahfidzFormOptions;

    protected static ?string $model = MemorizationRecord::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 2;

    public static function getModule(): string
    {
        return 'tahfidz';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.tahfidz.record.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.tahfidz.record.plural');
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['student', 'halaqah', 'fromSurah', 'toSurah']))
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('recorded_on')->orderByDesc('id'))
            ->columns([
                TextColumn::make('recorded_on')
                    ->label(__('pondok.tahfidz.fields.recorded_on'))
                    ->date('j M Y'),
                TextColumn::make('student.name')
                    ->label(__('pondok.tahfidz.fields.student_id'))
                    ->searchable(),
                TextColumn::make('halaqah.name')
                    ->label(__('pondok.tahfidz.fields.halaqah_id')),
                TextColumn::make('type')
                    ->label(__('pondok.tahfidz.fields.type'))
                    ->formatStateUsing(fn (string $state): string => __("pondok.tahfidz.record.types.{$state}")),
                TextColumn::make('range')
                    ->label(__('pondok.tahfidz.record.range'))
                    ->state(fn (MemorizationRecord $record): string => $record->rangeLabel()),
                TextColumn::make('pages')
                    ->label(__('pondok.tahfidz.fields.pages'))
                    ->formatStateUsing(fn (string $state): string => str_replace('.', ',', $state)),
                TextColumn::make('grade')
                    ->label(__('pondok.tahfidz.fields.grade'))
                    ->formatStateUsing(fn (string $state): string => __("pondok.tahfidz.record.grades.{$state}")),
                TextColumn::make('mistakes')
                    ->label(__('pondok.tahfidz.fields.mistakes'))
                    ->numeric(),
                TextColumn::make('session')
                    ->label(__('pondok.tahfidz.fields.session'))
                    ->formatStateUsing(fn (string $state): string => __("pondok.tahfidz.record.sessions.{$state}"))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('note')
                    ->label(__('pondok.tahfidz.fields.note'))
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('halaqah_id')
                    ->label(__('pondok.tahfidz.fields.halaqah_id'))
                    ->options(fn (): array => static::visibleHalaqahOptions()),
                SelectFilter::make('type')
                    ->label(__('pondok.tahfidz.fields.type'))
                    ->options(__('pondok.tahfidz.record.types')),
                Filter::make('recorded_on')
                    ->schema([
                        DatePicker::make('from')->label(__('pondok.tahfidz.record.date_from')),
                        DatePicker::make('until')->label(__('pondok.tahfidz.record.date_until')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('recorded_on', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('recorded_on', '<=', $date))),
            ])
            ->emptyStateHeading(__('pondok.tahfidz.record.empty'))
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentList)
            ->headerActions([
                static::createAction(),
            ])
            ->recordActions([
                static::deleteAction(),
            ]);
    }

    public static function createAction(): Action
    {
        $surahs = fn (): array => collect(QuranSurah::query()->orderBy('id')->pluck('name', 'id'))
            ->map(fn (string $name, int $id): string => __('pondok.tahfidz.record.surah_option', ['number' => $id, 'name' => $name]))
            ->all();

        return Action::make('create')
            ->label(__('pondok.tahfidz.record.create'))
            ->icon(Heroicon::OutlinedPlus)
            ->modalSubmitActionLabel(__('pondok.tahfidz.save'))
            ->authorize(fn (): bool => static::canCreate())
            ->schema([
                Section::make()
                    ->columns(2)
                    ->schema([
                        ...static::halaqahAndStudentFields(),
                        DatePicker::make('recorded_on')
                            ->label(__('pondok.tahfidz.fields.recorded_on'))
                            ->default(fn (): string => today()->toDateString())
                            ->maxDate(today())
                            ->required(),
                        Select::make('session')
                            ->label(__('pondok.tahfidz.fields.session'))
                            ->options(__('pondok.tahfidz.record.sessions'))
                            ->required(),
                        Select::make('type')
                            ->label(__('pondok.tahfidz.fields.type'))
                            ->options(__('pondok.tahfidz.record.types'))
                            ->default('ziyadah')
                            ->required(),
                        static::staffField('listener_staff_id'),
                        Select::make('from_surah_id')
                            ->label(__('pondok.tahfidz.fields.from_surah_id'))
                            ->options($surahs)
                            ->searchable()
                            ->required(),
                        TextInput::make('from_ayah')
                            ->label(__('pondok.tahfidz.fields.from_ayah'))
                            ->integer()
                            ->minValue(1)
                            ->required(),
                        Select::make('to_surah_id')
                            ->label(__('pondok.tahfidz.fields.to_surah_id'))
                            ->options($surahs)
                            ->searchable()
                            ->required(),
                        TextInput::make('to_ayah')
                            ->label(__('pondok.tahfidz.fields.to_ayah'))
                            ->integer()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('pages')
                            ->label(__('pondok.tahfidz.fields.pages'))
                            ->numeric()
                            ->step(0.1)
                            ->minValue(0.1)
                            ->maxValue(MemorizationRecord::MAX_PAGES)
                            ->required(),
                        Select::make('grade')
                            ->label(__('pondok.tahfidz.fields.grade'))
                            ->options(__('pondok.tahfidz.record.grades'))
                            ->required(),
                        TextInput::make('mistakes')
                            ->label(__('pondok.tahfidz.fields.mistakes'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(255)
                            ->default(0),
                        Textarea::make('note')
                            ->label(__('pondok.tahfidz.fields.note'))
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
            ])
            ->action(function (array $data, RecordMemorization $recordMemorization, Action $action): void {
                $saved = static::saveShowingFormErrors($action, fn () => $recordMemorization->handle(auth()->user(), $data));

                Notification::make()
                    ->title(__('pondok.tahfidz.record.created', ['name' => $saved->student->name]))
                    ->success()
                    ->send();
            });
    }

    public static function deleteAction(): Action
    {
        return Action::make('delete')
            ->label(__('pondok.tahfidz.record.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('pondok.tahfidz.record.delete_description'))
            // Baris tabel sudah tersaring `visibleTo`; Action memeriksa ulang per record.
            ->authorize(fn (): bool => auth()->user()->can('delete:memorization-record'))
            ->action(function (MemorizationRecord $record, DeleteMemorizationRecord $delete): void {
                $delete->handle(auth()->user(), $record);

                Notification::make()->title(__('pondok.tahfidz.record.deleted'))->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMemorizationRecords::route('/'),
        ];
    }
}
