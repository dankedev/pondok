<?php

namespace App\Filament\Resources\FeeOverrides;

use App\Actions\Keuangan\EndFeeOverride;
use App\Actions\Keuangan\SaveFeeOverride;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\FeeOverrides\Pages\ManageFeeOverrides;
use App\Filament\Resources\Scholarships\ScholarshipResource;
use App\Models\FeeOverride;
use App\Models\FeeType;
use App\Models\Scholarship;
use App\Models\Student;
use App\Models\Unit;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Keringanan per santri per jenis biaya. Simpan lewat `SaveFeeOverride`,
 * akhiri lewat `EndFeeOverride`; keringanan tidak dihapus.
 *
 * Cakupan jenjang mengikuti santri: query memakai `whereHas('student')` yang
 * membawa global scope `Student`. Keringanan milik pendaftar saja (tanpa
 * santri) belum dikelola di sini (F010/F011).
 */
class FeeOverrideResource extends Resource
{
    use BelongsToModule, ShowsActionErrorsOnForm;

    protected static ?string $model = FeeOverride::class;

    // Design guide §3: kapital hanya di awal kalimat.
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 4;

    public static function getModule(): string
    {
        return 'keuangan';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.fee_override.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.fee_override.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.keuangan');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereNotNull('student_id')
            ->whereHas('student');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('student_id')
                    ->label(__('pondok.fee_override.student'))
                    ->options(fn (?FeeOverride $record): array => Student::query()
                        ->where(fn (Builder $query) => $query
                            ->active()
                            ->when($record, fn (Builder $query, FeeOverride $record) => $query->orWhereKey($record->student_id)))
                        ->orderBy('name')
                        ->get(['id', 'nis', 'name'])
                        ->mapWithKeys(fn (Student $student): array => [$student->id => "{$student->nis} — {$student->name}"])
                        ->all())
                    ->searchable()
                    ->required()
                    ->disabledOn('edit'),
                Select::make('fee_type_id')
                    ->label(__('pondok.fee_override.fee_type'))
                    ->options(fn (?FeeOverride $record): array => FeeType::query()
                        ->where(fn (Builder $query) => $query
                            ->active()
                            ->when($record, fn (Builder $query, FeeOverride $record) => $query->orWhereKey($record->fee_type_id)))
                        ->orderBy('priority')
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
                Select::make('scholarship_id')
                    ->label(__('pondok.fee_override.scholarship'))
                    ->placeholder(__('pondok.fee_override.no_scholarship_placeholder'))
                    ->helperText(__('pondok.fee_override.scholarship_helper'))
                    ->options(fn (?FeeOverride $record): array => Scholarship::query()
                        ->where(fn (Builder $query) => $query
                            ->active()
                            ->when($record?->scholarship_id, fn (Builder $query, int $id) => $query->orWhereKey($id)))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        $scholarship = blank($state) ? null : Scholarship::query()->find($state);

                        if ($scholarship !== null) {
                            $set('mode', $scholarship->mode);
                            $set('value', $scholarship->default_value);
                        }
                    }),
                Select::make('mode')
                    ->label(__('pondok.fee_override.mode'))
                    ->options(ScholarshipResource::modeOptions())
                    ->default(FeeOverride::MODE_NOMINAL)
                    ->helperText(fn (Get $get): ?string => in_array($get('mode'), FeeOverride::MODES, true)
                        ? __("pondok.fee_override.mode_helpers.{$get('mode')}")
                        : null)
                    ->required()
                    ->live(),
                ScholarshipResource::valueInput('value')
                    ->label(__('pondok.fee_override.value')),
                DatePicker::make('valid_from')
                    ->label(__('pondok.fee_override.valid_from'))
                    ->default(fn (): string => today()->startOfMonth()->toDateString())
                    ->required(),
                DatePicker::make('valid_until')
                    ->label(__('pondok.fee_override.valid_until'))
                    ->helperText(__('pondok.fee_override.valid_until_helper')),
                TextInput::make('reason')
                    ->label(__('pondok.fee_override.reason'))
                    ->helperText(__('pondok.fee_override.reason_helper'))
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('valid_from', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['student', 'feeType', 'scholarship', 'approver']))
            ->columns([
                TextColumn::make('student.nis')
                    ->label(__('pondok.fee_override.nis'))
                    ->searchable(),
                TextColumn::make('student.name')
                    ->label(__('pondok.fee_override.student_name'))
                    ->searchable(),
                TextColumn::make('feeType.name')
                    ->label(__('pondok.fee_override.fee_type')),
                TextColumn::make('scholarship.name')
                    ->label(__('pondok.fee_override.scholarship'))
                    ->placeholder(__('pondok.fee_override.no_scholarship')),
                TextColumn::make('value')
                    ->label(__('pondok.fee_override.value_column'))
                    ->state(fn (FeeOverride $record): string => $record->valueLabel())
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums']),
                TextColumn::make('valid_from')
                    ->label(__('pondok.fee_override.valid_from'))
                    ->date('j M Y')
                    ->sortable(),
                TextColumn::make('valid_until')
                    ->label(__('pondok.fee_override.valid_until'))
                    ->date('j M Y')
                    ->placeholder(__('pondok.fee_override.until_graduation'))
                    ->sortable(),
                TextColumn::make('approver.name')
                    ->label(__('pondok.fee_override.approver'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reason')
                    ->label(__('pondok.fee_override.reason'))
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('fee_type_id')
                    ->label(__('pondok.fee_override.fee_type'))
                    ->relationship('feeType', 'name'),
                SelectFilter::make('scholarship_id')
                    ->label(__('pondok.fee_override.scholarship'))
                    ->relationship('scholarship', 'name'),
                SelectFilter::make('unit')
                    ->label(__('pondok.fee_override.unit'))
                    ->options(fn (): array => Unit::query()->accessible()->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, mixed $unitId) => $query->whereHas('student', fn (Builder $query) => $query->where('unit_id', $unitId)),
                    )),
                SelectFilter::make('validity')
                    ->label(__('pondok.fee_override.validity'))
                    ->options(collect(['aktif', 'akan_datang', 'berakhir'])
                        ->mapWithKeys(fn (string $option): array => [$option => __("pondok.fee_override.validity_options.{$option}")])
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'aktif' => $query->activeOn(today()),
                        'akan_datang' => $query->whereDate('valid_from', '>', today()->toDateString()),
                        'berakhir' => $query->whereDate('valid_until', '<', today()->toDateString()),
                        default => $query,
                    }),
            ])
            ->emptyStateHeading(__('pondok.fee_override.empty'))
            ->recordActions([
                EditAction::make()
                    ->using(fn (FeeOverride $record, array $data, SaveFeeOverride $saveFeeOverride, Action $action): FeeOverride => static::saveShowingFormErrors(
                        $action,
                        fn (): FeeOverride => $saveFeeOverride->handle($data, auth()->user(), $record),
                    )),
                ActionGroup::make([
                    static::endAction(),
                ]),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->using(fn (array $data, SaveFeeOverride $saveFeeOverride, Action $action): FeeOverride => static::saveShowingFormErrors(
                $action,
                fn (): FeeOverride => $saveFeeOverride->handle($data, auth()->user()),
            ));
    }

    /**
     * Mengisi tanggal berakhir; tersembunyi untuk keringanan yang sudah berakhir.
     */
    public static function endAction(): Action
    {
        return Action::make('end')
            ->label(__('pondok.fee_override.end_action'))
            ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('pondok.fee_override.end_description'))
            ->authorize(fn (FeeOverride $record): bool => static::canEdit($record))
            ->hidden(fn (FeeOverride $record): bool => $record->valid_until !== null && $record->valid_until->lt(today()))
            ->schema([
                DatePicker::make('end_on')
                    ->label(__('pondok.fee_override.end_on'))
                    ->default(fn (): string => today()->toDateString())
                    ->required(),
            ])
            ->action(function (FeeOverride $record, array $data, EndFeeOverride $end, Action $action): void {
                $endOn = CarbonImmutable::parse($data['end_on']);

                try {
                    $end->handle($record, $endOn);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages([
                        "mountedActions.{$action->getNestingIndex()}.data.end_on" => $exception->validator->errors()->all(),
                    ]);
                }

                Notification::make()
                    ->title(__('pondok.fee_override.ended', [
                        'fee_type' => $record->feeType->name,
                        'student' => $record->student->name,
                        'date' => $endOn->translatedFormat('j M Y'),
                    ]))
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFeeOverrides::route('/'),
        ];
    }
}
