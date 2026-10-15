<?php

namespace App\Filament\Resources\AdmissionTracks;

use App\Actions\Psb\SaveAdmissionTrack;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\AdmissionTracks\Pages\ManageAdmissionTracks;
use App\Models\AdmissionTrack;
use App\Models\Scholarship;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Jalur pendaftaran beserta program beasiswanya. Simpan selalu lewat Action
 * `SaveAdmissionTrack`. Jalur tidak dihapus, cukup dinonaktifkan.
 */
class AdmissionTrackResource extends Resource
{
    use BelongsToModule, ShowsActionErrorsOnForm;

    protected static ?string $model = AdmissionTrack::class;

    // Design guide §3: kapital hanya di awal kalimat.
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 2;

    public static function getModule(): string
    {
        return 'psb';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.admission_track.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.admission_track.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.psb');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('code')
                    ->label(__('pondok.admission_track.code'))
                    ->helperText(__('pondok.admission_track.code_helper'))
                    ->required()
                    ->maxLength(30),
                TextInput::make('name')
                    ->label(__('pondok.admission_track.name'))
                    ->required()
                    ->maxLength(255),
                Select::make('scholarship_id')
                    ->label(__('pondok.admission_track.scholarship'))
                    ->placeholder(__('pondok.admission_track.no_scholarship'))
                    ->helperText(__('pondok.admission_track.scholarship_helper'))
                    ->options(fn (?AdmissionTrack $record): array => Scholarship::query()
                        ->where(fn (Builder $query) => $query
                            ->where('is_active', true)
                            ->when($record?->scholarship_id, fn (Builder $query, int $id) => $query->orWhereKey($id)))
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (Scholarship $scholarship): array => [
                            $scholarship->id => "{$scholarship->name} ({$scholarship->defaultValueLabel()})",
                        ])
                        ->all()),
                Toggle::make('is_active')
                    ->label(__('pondok.admission_track.is_active'))
                    ->helperText(__('pondok.admission_track.is_active_helper'))
                    ->default(true),
                Textarea::make('terms')
                    ->label(__('pondok.admission_track.terms'))
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('scholarship'))
            ->columns([
                TextColumn::make('code')
                    ->label(__('pondok.admission_track.code'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(__('pondok.admission_track.name'))
                    ->searchable(),
                TextColumn::make('scholarship.name')
                    ->label(__('pondok.admission_track.scholarship'))
                    ->placeholder(__('pondok.admission_track.no_scholarship'))
                    ->description(fn (AdmissionTrack $record): ?string => $record->scholarship?->defaultValueLabel()),
                IconColumn::make('is_active')
                    ->label(__('pondok.admission_track.is_active'))
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('pondok.admission_track.is_active')),
            ])
            ->emptyStateHeading(__('pondok.admission_track.empty'))
            ->recordActions([
                EditAction::make()
                    ->using(fn (AdmissionTrack $record, array $data, SaveAdmissionTrack $saveAdmissionTrack, Action $action): AdmissionTrack => static::saveShowingFormErrors(
                        $action,
                        fn (): AdmissionTrack => $saveAdmissionTrack->handle($data, $record),
                    )),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->using(fn (array $data, SaveAdmissionTrack $saveAdmissionTrack, Action $action): AdmissionTrack => static::saveShowingFormErrors(
                $action,
                fn (): AdmissionTrack => $saveAdmissionTrack->handle($data),
            ));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAdmissionTracks::route('/'),
        ];
    }
}
