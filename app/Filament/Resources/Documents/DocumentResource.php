<?php

namespace App\Filament\Resources\Documents;

use App\Actions\Dokumen\ReplaceDocument;
use App\Actions\Dokumen\UploadDocument;
use App\Actions\Dokumen\VerifyDocument;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Guardian;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Unit;
use App\Models\User;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Antrean verifikasi dan pencarian dokumen lintas pemilik. Tanpa create,
 * edit, maupun hapus: dokumen diunggah dari halaman pemilik
 * (`DocumentsRelationManager`), dikoreksi lewat versi baru atau status
 * `ditolak`. Aksi baris didefinisikan di sini dan dipakai ulang relation manager.
 */
class DocumentResource extends Resource
{
    use BelongsToModule, ShowsActionErrorsOnForm;

    protected static ?string $model = Document::class;

    // Design guide §3: kapital hanya di awal kalimat.
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 1;

    /** Field form unggah/versi baru; galat Action lain tampil sebagai notifikasi. */
    private const FORM_FIELDS = ['document_type_id', 'file', 'number', 'issued_on', 'expires_on', 'status'];

    public static function getModule(): string
    {
        return 'dokumen';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.document.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.document.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.dokumen');
    }

    /**
     * Hanya jenis pemilik yang boleh dilihat user (`Document::visibleOwnerTypesFor`); super-admin tanpa saringan.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = static::user();

        if ($user === null || Document::seesEveryOwnerType($user)) {
            return $query;
        }

        return $query->whereIn('documentable_type', Document::visibleOwnerTypesFor($user));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(static::eagerLoads()))
            ->columns([
                static::documentTypeColumn(),
                TextColumn::make('documentable.name')
                    ->label(__('pondok.document.owner'))
                    ->description(fn (Document $record): ?string => $record->documentable instanceof Student ? $record->documentable->nis : null)
                    ->searchable(query: fn (Builder $query, string $search): Builder => static::searchOwner($query, $search)),
                TextColumn::make('documentable_type')
                    ->label(__('pondok.document.owner_type'))
                    ->formatStateUsing(fn (string $state): string => static::ownerTypeLabel($state)),
                TextColumn::make('unit')
                    ->label(__('pondok.common.unit'))
                    ->state(fn (Document $record): ?string => $record->documentable instanceof Student ? $record->documentable->unit?->name : null)
                    ->placeholder('-'),
                static::numberColumn(),
                TextColumn::make('expires_on')
                    ->label(__('pondok.document.expires_on'))
                    ->date('j M Y')
                    ->placeholder('-'),
                static::statusColumn(),
                TextColumn::make('created_at')
                    ->label(__('pondok.document.uploaded_at'))
                    ->dateTime('j M Y, H.i')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('uploader.name')
                    ->label(__('pondok.document.uploaded_by'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('verifier.name')
                    ->label(__('pondok.document.verified_by'))
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('verified_at')
                    ->label(__('pondok.document.verified_at'))
                    ->dateTime('j M Y, H.i')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                static::statusFilter()->default(Document::STATUS_PENDING),
                SelectFilter::make('document_type_id')
                    ->label(__('pondok.document.document_type'))
                    ->relationship('documentType', 'name'),
                SelectFilter::make('documentable_type')
                    ->label(__('pondok.document.owner_type'))
                    ->options(fn (): array => collect(Document::DOCUMENTABLE_TYPES)
                        ->keys()
                        ->filter(fn (string $alias): bool => ($user = static::user()) !== null && Document::userSeesOwnerType($user, $alias))
                        ->mapWithKeys(fn (string $alias): array => [$alias => static::ownerTypeLabel($alias)])
                        ->all()),
                SelectFilter::make('unit_id')
                    ->label(__('pondok.common.unit'))
                    ->options(fn (): array => Unit::query()->accessible()->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query : $query
                        ->where('documentable_type', 'student')
                        ->whereIn('documentable_id', Student::withoutGlobalScopes()->where('unit_id', $data['value'])->select('id'))),
                static::currentFilter(),
                TernaryFilter::make('expired')
                    ->label(__('pondok.document.expired_filter'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->expired(),
                        false: fn (Builder $query): Builder => $query->where(fn (Builder $query) => $query
                            ->whereNull('expires_on')
                            ->orWhere('expires_on', '>=', today()->toDateString())),
                    ),
            ])
            ->emptyStateIcon(Heroicon::OutlinedDocumentText)
            ->emptyStateHeading(fn (Table $table): string => ($table->getLivewire()->getTableFilterState('status')['value'] ?? null) === Document::STATUS_PENDING
                ? __('pondok.document.empty_pending')
                : __('pondok.document.empty'))
            ->recordActions([
                static::actionGroup(),
            ]);
    }

    /**
     * Relasi untuk tabel dokumen, tanpa query per baris.
     *
     * @return array<int|string, mixed>
     */
    public static function eagerLoads(): array
    {
        return [
            'documentType',
            'uploader',
            'verifier',
            'documentable' => fn (MorphTo $morphTo) => $morphTo->morphWith([Student::class => ['unit']]),
        ];
    }

    public static function documentTypeColumn(): TextColumn
    {
        return TextColumn::make('documentType.name')
            ->label(__('pondok.document.document_type'));
    }

    /**
     * Nomor dimasker bagi yang boleh membuka berkas; "Rahasia" bagi yang tidak.
     */
    public static function numberColumn(): TextColumn
    {
        return TextColumn::make('number')
            ->label(__('pondok.document.number'))
            ->state(fn (Document $record): ?string => static::canDownload($record)
                ? $record->maskedNumber()
                : ($record->isConfidential() ? __('pondok.document.confidential') : null))
            ->placeholder('-')
            ->extraAttributes(['class' => 'tabular-nums']);
    }

    public static function statusColumn(): TextColumn
    {
        return TextColumn::make('display_status')
            ->label(__('pondok.document.status'))
            ->badge()
            ->formatStateUsing(fn (string $state): string => __("pondok.document.statuses.{$state}"))
            ->color(fn (string $state): string => Document::STATUS_COLORS[$state] ?? 'gray');
    }

    public static function statusFilter(): SelectFilter
    {
        return SelectFilter::make('status')
            ->label(__('pondok.document.status'))
            ->options(collect(Document::STATUSES)->mapWithKeys(fn (string $status): array => [$status => __("pondok.document.statuses.{$status}")])->all());
    }

    public static function currentFilter(): TernaryFilter
    {
        return TernaryFilter::make('current')
            ->label(__('pondok.document.current_only'))
            ->default(true)
            ->queries(
                true: fn (Builder $query): Builder => $query->current(),
                false: fn (Builder $query): Builder => $query->whereNotNull('replaced_by_id'),
            );
    }

    public static function actionGroup(): ActionGroup
    {
        return ActionGroup::make([
            static::viewAction(),
            static::downloadAction(),
            static::verifyAction(),
            static::replaceAction(),
        ]);
    }

    public static function downloadUrl(Document $document): string
    {
        return route('filament.admin.dokumen.download', ['document' => $document]);
    }

    public static function viewAction(): Action
    {
        $entry = fn (string $key, string $label): TextEntry => TextEntry::make("detail_{$key}")
            ->label($label)
            ->state(fn (Get $get): mixed => $get("detail.{$key}"))
            ->placeholder('-');

        return Action::make('view')
            ->label(__('pondok.document.view_action'))
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->authorize(fn (Document $record): bool => Gate::allows('view', $record))
            // Isi detail diambil saat aksi dibuka dan disimpan di state modal, lalu halaman dirender
            // penuh (bukan hanya partial modal) tanpa bergantung pada record baris tabel terakhir.
            ->mountUsing(function (Schema $schema, Document $record, HasActions $livewire): void {
                $schema->fill(['detail' => static::detail($record)]);
                $livewire->forceRender();
            })
            ->modalHeading(fn (Document $record): string => (string) $record->documentType?->name)
            ->modalSubmitAction(false)
            ->schema([
                Hidden::make('detail')->dehydrated(false),
                Grid::make(2)->schema([
                    $entry('owner', __('pondok.document.owner')),
                    $entry('number', __('pondok.document.number')),
                    $entry('issued_on', __('pondok.document.issued_on')),
                    $entry('expires_on', __('pondok.document.expires_on')),
                    $entry('original_name', __('pondok.document.original_name')),
                    $entry('size', __('pondok.document.size_kb')),
                    $entry('status', __('pondok.document.status'))
                        ->badge()
                        ->color(fn (Get $get): string => Document::STATUS_COLORS[$get('detail.status_key')] ?? 'gray'),
                    $entry('uploaded', __('pondok.document.uploaded_by')),
                    $entry('verified', __('pondok.document.verified_by')),
                ]),
            ]);
    }

    /**
     * Isi modal detail. Nomor lengkap dan nama file asli hanya bagi yang boleh membuka berkas.
     *
     * @return array<string, ?string>
     */
    protected static function detail(Document $record): array
    {
        $canDownload = static::canDownload($record);
        $confidential = __('pondok.document.confidential');

        return [
            'owner' => $record->documentable?->getAttribute('name'),
            'number' => $canDownload ? $record->number : ($record->isConfidential() ? $confidential : $record->maskedNumber()),
            'issued_on' => $record->issued_on?->translatedFormat('j M Y'),
            'expires_on' => $record->expires_on?->translatedFormat('j M Y'),
            'original_name' => $canDownload ? $record->original_name : $confidential,
            'size' => __('pondok.document.size_value', ['size' => number_format((int) $record->size_kb, 0, ',', '.')]),
            'status' => __("pondok.document.statuses.{$record->display_status}"),
            'status_key' => $record->display_status,
            'uploaded' => $record->uploader?->name.', '.$record->created_at?->translatedFormat('j M Y, H.i'),
            'verified' => $record->verifier === null ? null : $record->verifier->name.', '.$record->verified_at?->translatedFormat('j M Y, H.i'),
        ];
    }

    public static function downloadAction(): Action
    {
        return Action::make('download')
            ->label(__('pondok.document.download_action'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->authorize(fn (Document $record): bool => static::canDownload($record))
            ->url(fn (Document $record): string => static::downloadUrl($record), shouldOpenInNewTab: true);
    }

    public static function verifyAction(): Action
    {
        return Action::make('verify')
            ->label(__('pondok.document.verify_action'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            // Super-admin melewati policy (gate `before`); versi yang sudah diganti tetap tidak bisa diverifikasi.
            ->authorize(fn (Document $record): bool => $record->isCurrent() && Gate::allows('verify', $record))
            ->modalHeading(fn (Document $record): string => __('pondok.document.verify_action').': '.$record->documentType?->name)
            ->modalDescription(__('pondok.document.verify_description'))
            ->schema([
                Radio::make('status')
                    ->label(__('pondok.document.status'))
                    ->options([
                        Document::STATUS_VALID => __('pondok.document.statuses.valid'),
                        Document::STATUS_REJECTED => __('pondok.document.statuses.ditolak'),
                    ])
                    ->required(),
            ])
            ->action(function (Document $record, array $data, VerifyDocument $verifyDocument, Action $action): void {
                $document = static::runDocumentAction($action, fn (): Document => $verifyDocument->handle($record, (string) ($data['status'] ?? ''), static::user()));

                Notification::make()
                    ->title(__('pondok.document.verified', [
                        'type' => $document->documentType?->name,
                        'status' => mb_strtolower(__("pondok.document.statuses.{$document->status}")),
                    ]))
                    ->success()
                    ->send();
            });
    }

    public static function replaceAction(): Action
    {
        return Action::make('replace')
            ->label(__('pondok.document.replace_action'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->authorize(fn (Document $record): bool => static::canReplace($record))
            ->modalHeading(fn (Document $record): string => __('pondok.document.replace_action').': '.$record->documentType?->name)
            ->modalDescription(__('pondok.document.replace_description'))
            ->schema(fn (Document $record): array => static::fileFields(fn (): bool => (bool) $record->documentType?->has_expiry))
            ->action(function (Document $record, array $data, ReplaceDocument $replaceDocument, Action $action): void {
                $document = static::runDocumentAction($action, function () use ($record, $data, $replaceDocument): Document {
                    // Pemeriksaan ulang di server: jenis rahasia butuh `view-confidential:document`.
                    static::ensureUploadTypeAllowed($record->documentType);

                    return $replaceDocument->handle($record, static::fileData($data), static::user());
                });

                Notification::make()
                    ->title(__('pondok.document.replaced', ['type' => $document->documentType?->name]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Aksi "Unggah dokumen" untuk pemilik `$owner` (dipakai relation manager).
     *
     * @param  Closure(): Model  $owner
     */
    public static function uploadAction(Closure $owner): Action
    {
        return Action::make('upload')
            ->label(__('pondok.document.upload_action'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('primary')
            ->authorize(fn (): bool => static::canUploadFor($owner()))
            ->modalHeading(__('pondok.document.upload_action'))
            ->schema(fn (): array => [
                Select::make('document_type_id')
                    ->label(__('pondok.document.document_type'))
                    ->options(fn (): array => static::uploadTypeOptions($owner()))
                    // Label dari database (bukan dari opsi) agar jenis yang tidak ditawarkan tetap sampai ke
                    // pemeriksaan server dan mendapat pesan yang tepat (`confidential_not_allowed`).
                    ->getOptionLabelUsing(fn (mixed $value): ?string => is_numeric($value) ? DocumentType::query()->whereKey($value)->value('name') : null)
                    ->required()
                    ->live(),
                ...static::fileFields(fn (Get $get): bool => (bool) DocumentType::query()->whereKey($get('document_type_id') ?: 0)->value('has_expiry')),
            ])
            ->action(function (array $data, UploadDocument $uploadDocument, Action $action) use ($owner): void {
                $documentable = $owner();

                $document = static::runDocumentAction($action, function () use ($data, $documentable, $uploadDocument): Document {
                    $type = DocumentType::query()->find($data['document_type_id'] ?? 0);

                    if ($type !== null) {
                        static::ensureUploadTypeAllowed($type, 'document_type_id');
                    }

                    return $uploadDocument->handle($documentable, [...static::fileData($data), 'document_type_id' => $data['document_type_id'] ?? null], static::user());
                });

                Notification::make()
                    ->title(__('pondok.document.uploaded', ['type' => $document->documentType?->name, 'owner' => $documentable->getAttribute('name')]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Jenis aktif untuk jenis pemilik `$owner`; jenis rahasia hanya bagi yang boleh.
     *
     * @return array<int, string>
     */
    public static function uploadTypeOptions(Model $owner): array
    {
        $alias = Document::aliasFor($owner);
        $owners = array_keys(Document::OWNER_TO_MORPH, $alias, true);

        return DocumentType::query()
            ->active()
            ->whereIn('owner', $owners)
            ->orderBy('name')
            ->get()
            ->filter(fn (DocumentType $type): bool => Gate::allows('uploadType', [Document::class, $type]))
            ->pluck('name', 'id')
            ->all();
    }

    public static function canDownload(Document $document): bool
    {
        return Gate::allows('download', $document);
    }

    public static function canUploadFor(Model $owner): bool
    {
        $user = static::user();
        $alias = Document::aliasFor($owner);

        return $user !== null
            && $alias !== null
            && Gate::allows('create', Document::class)
            && Document::userSeesOwnerType($user, $alias)
            && ! ($owner instanceof Student && $owner->trashed());
    }

    public static function canReplace(Document $document): bool
    {
        $owner = $document->documentable;

        return $document->isCurrent()
            && $owner instanceof Model
            && $document->documentType !== null
            && static::canUploadFor($owner)
            && Gate::allows('uploadType', [Document::class, $document->documentType]);
    }

    public static function ownerTypeLabel(string $alias): string
    {
        $key = "pondok.document.owner_types.{$alias}";

        return __($key) === $key ? $alias : __($key);
    }

    /**
     * Cari nama pemilik (santri, wali, staff) atau NIS santri.
     */
    protected static function searchOwner(Builder $query, string $search): Builder
    {
        $like = '%'.$search.'%';

        return $query->whereHasMorph('documentable', [Student::class, Guardian::class, Staff::class], function (Builder $query, string $type) use ($like): void {
            $query->where(function (Builder $query) use ($type, $like): void {
                $query->where('name', 'like', $like);

                if ($type === Student::class) {
                    $query->orWhere('nis', 'like', $like);
                }
            });
        });
    }

    /**
     * Field berkas, nomor, dan tanggal (unggah dan versi baru).
     *
     * @param  Closure(Get): bool|Closure(): bool  $hasExpiry
     * @return list<mixed>
     */
    protected static function fileFields(Closure $hasExpiry): array
    {
        return [
            FileUpload::make('file')
                ->label(__('pondok.document.file'))
                ->helperText(__('pondok.document.file_helper'))
                ->storeFiles(false)
                ->acceptedFileTypes(Document::MIME_TYPES)
                ->maxSize(Document::MAX_SIZE_KB)
                ->required(),
            TextInput::make('number')
                ->label(__('pondok.document.number'))
                ->helperText(__('pondok.document.number_helper'))
                ->maxLength(Document::NUMBER_MAX_LENGTH),
            DatePicker::make('issued_on')
                ->label(__('pondok.document.issued_on')),
            DatePicker::make('expires_on')
                ->label(__('pondok.document.expires_on'))
                ->visible($hasExpiry),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{file: mixed, number: mixed, issued_on: mixed, expires_on: mixed}
     */
    protected static function fileData(array $data): array
    {
        $file = $data['file'] ?? null;

        if (is_array($file)) {
            $file = collect($file)->first(fn (mixed $item): bool => $item instanceof UploadedFile);
        }

        return [
            'file' => $file,
            'number' => $data['number'] ?? null,
            'issued_on' => $data['issued_on'] ?? null,
            'expires_on' => $data['expires_on'] ?? null,
        ];
    }

    protected static function ensureUploadTypeAllowed(?DocumentType $type, string $field = 'document_type_id'): void
    {
        if ($type !== null && ! Gate::allows('uploadType', [Document::class, $type])) {
            throw ValidationException::withMessages([$field => __('pondok.dokumen.errors.confidential_not_allowed')]);
        }
    }

    /**
     * Jalankan Action dokumen: galat field tampil di form modal, galat lain (pemilik, versi) sebagai notifikasi.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $run
     * @return TResult
     */
    protected static function runDocumentAction(Action $action, Closure $run): mixed
    {
        try {
            return $run();
        } catch (ValidationException $exception) {
            $other = collect($exception->errors())->except(self::FORM_FIELDS);

            if ($other->isNotEmpty()) {
                Notification::make()->title($other->flatten()->first())->danger()->send();
                $action->halt();
            }

            return static::runShowingFormErrors($action, fn () => throw $exception);
        }
    }

    protected static function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    public static function moduleEnabled(): bool
    {
        return Setting::moduleEnabled(static::getModule());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocuments::route('/'),
        ];
    }
}
