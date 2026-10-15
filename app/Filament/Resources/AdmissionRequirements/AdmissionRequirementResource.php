<?php

namespace App\Filament\Resources\AdmissionRequirements;

use App\Actions\Psb\SaveAdmissionRequirement;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\AdmissionRequirements\Pages\ManageAdmissionRequirements;
use App\Models\AdmissionRequirement;
use App\Models\DocumentType;
use App\Models\Unit;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Syarat berkas PSB per jenjang atau semua jenjang. Simpan selalu lewat
 * Action `SaveAdmissionRequirement`. User bercakupan melihat syarat semua
 * jenjang (lihat saja) dan syarat jenjangnya.
 */
class AdmissionRequirementResource extends Resource
{
    use BelongsToModule, ShowsActionErrorsOnForm;

    protected static ?string $model = AdmissionRequirement::class;

    // Design guide §3: kapital hanya di awal kalimat.
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 3;

    public static function getModule(): string
    {
        return 'psb';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.admission_requirement.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.admission_requirement.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.psb');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where(fn (Builder $query) => $query
            ->whereNull('unit_id')
            ->orWhereIn('unit_id', Unit::query()->accessible()->select('id')));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('unit_id')
                    ->label(__('pondok.admission_requirement.unit'))
                    ->placeholder(__('pondok.admission_requirement.all_units'))
                    ->options(fn (): array => Unit::query()->accessible()->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->required(fn (): bool => static::userIsScoped()),
                Select::make('document_type_id')
                    ->label(__('pondok.admission_requirement.document_type'))
                    ->options(fn (?AdmissionRequirement $record): array => DocumentType::query()
                        ->forStudents()
                        ->where(fn (Builder $query) => $query
                            ->where('is_active', true)
                            ->when($record, fn (Builder $query, AdmissionRequirement $record) => $query->orWhereKey($record->document_type_id)))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                        if (blank($get('name')) && filled($state)) {
                            $set('name', DocumentType::query()->whereKey($state)->value('name'));
                        }
                    }),
                TextInput::make('name')
                    ->label(__('pondok.admission_requirement.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('sort_order')
                    ->label(__('pondok.admission_requirement.sort_order'))
                    ->integer()
                    ->minValue(0)
                    ->maxValue(255)
                    ->default(0),
                Toggle::make('is_required')
                    ->label(__('pondok.admission_requirement.is_required'))
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => $query->orderBy('sort_order')->orderBy('id'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['unit', 'documentType']))
            ->columns([
                TextColumn::make('unit.name')
                    ->label(__('pondok.admission_requirement.unit'))
                    ->placeholder(__('pondok.admission_requirement.all_units')),
                TextColumn::make('name')
                    ->label(__('pondok.admission_requirement.name'))
                    ->searchable(),
                TextColumn::make('documentType.name')
                    ->label(__('pondok.admission_requirement.document_type')),
                TextColumn::make('is_required')
                    ->label(__('pondok.admission_requirement.is_required_label'))
                    ->formatStateUsing(fn (bool $state): string => __('pondok.admission_requirement.required_labels.'.(int) $state)),
                TextColumn::make('sort_order')
                    ->label(__('pondok.admission_requirement.sort_order'))
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('unit_id')
                    ->label(__('pondok.admission_requirement.unit'))
                    ->relationship('unit', 'name', fn (Builder $query) => $query->accessible()),
                SelectFilter::make('document_type_id')
                    ->label(__('pondok.admission_requirement.document_type'))
                    ->relationship('documentType', 'name'),
            ])
            ->emptyStateHeading(__('pondok.admission_requirement.empty'))
            ->recordActions([
                EditAction::make()
                    ->hidden(fn (AdmissionRequirement $record): bool => static::isReadOnlyFor($record))
                    ->using(fn (AdmissionRequirement $record, array $data, SaveAdmissionRequirement $saveAdmissionRequirement, Action $action): AdmissionRequirement => static::saveShowingFormErrors(
                        $action,
                        fn (): AdmissionRequirement => $saveAdmissionRequirement->handle($data, $record),
                    )),
                DeleteAction::make()
                    ->modalDescription(__('pondok.admission_requirement.delete_description'))
                    ->hidden(fn (AdmissionRequirement $record): bool => static::isReadOnlyFor($record)),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->using(fn (array $data, SaveAdmissionRequirement $saveAdmissionRequirement, Action $action): AdmissionRequirement => static::saveShowingFormErrors(
                $action,
                fn (): AdmissionRequirement => $saveAdmissionRequirement->handle($data),
            ));
    }

    /**
     * Syarat semua jenjang hanya bisa diubah atau dihapus user tanpa cakupan jenjang.
     */
    public static function isReadOnlyFor(AdmissionRequirement $record): bool
    {
        return $record->unit_id === null && static::userIsScoped();
    }

    protected static function userIsScoped(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->scopedUnitIds() !== [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAdmissionRequirements::route('/'),
        ];
    }
}
