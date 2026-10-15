<?php

namespace App\Filament\Resources\DocumentTypes;

use App\Actions\Dokumen\SaveDocumentType;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\DocumentTypes\Pages\ManageDocumentTypes;
use App\Models\DocumentType;
use App\Models\Scopes\UnitScope;
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
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Jenis dokumen (KK, akta, ijazah, ...). Simpan lewat Action
 * `SaveDocumentType`. Jenis yang sudah dipakai dokumen (semua jenjang) atau
 * syarat PSB tidak bisa dihapus dan owner-nya dikunci.
 */
class DocumentTypeResource extends Resource
{
    use BelongsToModule, ShowsActionErrorsOnForm;

    protected static ?string $model = DocumentType::class;

    // Design guide §3: kapital hanya di awal kalimat.
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    public static function getModule(): string
    {
        return 'dokumen';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.document_type.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.document_type.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.dokumen');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('code')
                    ->label(__('pondok.document_type.code'))
                    ->helperText(__('pondok.document_type.code_helper'))
                    ->required()
                    ->maxLength(30)
                    ->disabled(fn (?DocumentType $record): bool => $record !== null),
                TextInput::make('name')
                    ->label(__('pondok.document_type.name'))
                    ->required()
                    ->maxLength(255),
                Select::make('owner')
                    ->label(__('pondok.document_type.owner'))
                    ->options(__('pondok.document_type.owners'))
                    ->default(DocumentType::OWNER_STUDENT)
                    ->required()
                    ->live()
                    ->disabled(fn (?DocumentType $record): bool => $record?->isInUse() ?? false),
                Toggle::make('is_required')
                    ->label(__('pondok.document_type.is_required'))
                    ->helperText(__('pondok.document_type.is_required_helper'))
                    ->visible(fn (Get $get): bool => $get('owner') === DocumentType::OWNER_STUDENT),
                Toggle::make('has_expiry')
                    ->label(__('pondok.document_type.has_expiry')),
                Toggle::make('is_confidential')
                    ->label(__('pondok.document_type.is_confidential'))
                    ->helperText(__('pondok.document_type.is_confidential_helper')),
                Toggle::make('is_active')
                    ->label(__('pondok.document_type.is_active'))
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        // Pemeriksaan pemakaian mengabaikan cakupan jenjang agar dokumen jenjang lain tetap mengunci jenis.
        $unscoped = fn (Builder $query) => $query->withoutGlobalScope(UnitScope::class);

        return $table
            ->defaultSort(fn (Builder $query) => $query->orderBy('owner')->orderBy('name'))
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount(['documents' => $unscoped])
                ->withExists(['documents' => $unscoped, 'admissionRequirements']))
            ->columns([
                TextColumn::make('code')
                    ->label(__('pondok.document_type.code'))
                    ->searchable(),
                TextColumn::make('name')
                    ->label(__('pondok.document_type.name'))
                    ->searchable(),
                TextColumn::make('owner')
                    ->label(__('pondok.document_type.owner'))
                    ->formatStateUsing(fn (string $state): string => __("pondok.document_type.owners.{$state}")),
                TextColumn::make('is_required')
                    ->label(__('pondok.document_type.is_required'))
                    ->formatStateUsing(fn (bool $state): string => __('pondok.document_type.required_labels.'.(int) $state)),
                IconColumn::make('is_confidential')
                    ->label(__('pondok.document_type.is_confidential'))
                    ->boolean(),
                IconColumn::make('has_expiry')
                    ->label(__('pondok.document_type.has_expiry'))
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label(__('pondok.document_type.is_active'))
                    ->boolean(),
                TextColumn::make('documents_count')
                    ->label(__('pondok.document_type.documents_count'))
                    ->alignEnd()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('owner')
                    ->label(__('pondok.document_type.owner'))
                    ->options(__('pondok.document_type.owners')),
                TernaryFilter::make('is_active')
                    ->label(__('pondok.document_type.is_active')),
            ])
            ->emptyStateHeading(__('pondok.document_type.empty'))
            ->recordActions([
                EditAction::make()
                    ->using(fn (DocumentType $record, array $data, SaveDocumentType $saveDocumentType, Action $action): DocumentType => static::saveShowingFormErrors(
                        $action,
                        // Field yang dikunci (disabled) tidak terkirim; nilai lama dipakai.
                        fn (): DocumentType => $saveDocumentType->handle($data + ['owner' => $record->owner], $record),
                    )),
                DeleteAction::make()
                    ->modalDescription(__('pondok.document_type.delete_description'))
                    ->hidden(fn (DocumentType $record): bool => (bool) $record->documents_exists || (bool) $record->admission_requirements_exists),
            ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->using(fn (array $data, SaveDocumentType $saveDocumentType, Action $action): DocumentType => static::saveShowingFormErrors(
                $action,
                fn (): DocumentType => $saveDocumentType->handle($data),
            ));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDocumentTypes::route('/'),
        ];
    }
}
