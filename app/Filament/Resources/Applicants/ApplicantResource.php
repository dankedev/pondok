<?php

namespace App\Filament\Resources\Applicants;

use App\Actions\Psb\DeleteApplicant;
use App\Actions\Psb\GenerateRegistrationInvoices;
use App\Actions\Psb\ResetApplicantToken;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\Applicants\Pages\ListApplicants;
use App\Filament\Resources\Applicants\Pages\ViewApplicant;
use App\Filament\Resources\Applicants\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\AdmissionTrack;
use App\Models\AdmissionWave;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Unit;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Daftar dan detail pendaftar PSB. Pendaftar dibuat lewat halaman publik
 * (`SubmitApplication`), jadi tanpa Create dan Edit. Hapus hanya untuk spam
 * atau pendaftaran ganda lewat `DeleteApplicant`.
 *
 * Cakupan jenjang mengikuti global scope `Applicant`.
 */
class ApplicantResource extends Resource
{
    use BelongsToModule, ShowsActionErrorsOnForm;

    protected static ?string $model = Applicant::class;

    // Design guide §3: kapital hanya di awal kalimat.
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static ?int $navigationSort = 0;

    protected static ?string $recordTitleAttribute = 'registration_no';

    public static function getModule(): string
    {
        return 'psb';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.applicant.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.applicant.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.psb');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('pondok.applicant.section_registration'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('registration_no')
                            ->label(__('pondok.applicant.registration_no'))
                            ->extraAttributes(['class' => 'tabular-nums']),
                        TextEntry::make('admissionWave.name')
                            ->label(__('pondok.applicant.wave'))
                            ->state(fn (Applicant $record): string => "{$record->admissionWave?->name} — {$record->admissionWave?->academicYear?->name}"),
                        TextEntry::make('admissionTrack.name')
                            ->label(__('pondok.applicant.track')),
                        TextEntry::make('unit.name')
                            ->label(__('pondok.applicant.unit')),
                        TextEntry::make('status')
                            ->label(__('pondok.applicant.status'))
                            ->badge()
                            ->formatStateUsing(fn (Applicant $record): string => $record->statusLabel())
                            ->color(fn (Applicant $record): string => $record->statusColor()),
                        TextEntry::make('created_at')
                            ->label(__('pondok.applicant.registered_at'))
                            ->dateTime('j M Y, H.i'),
                        TextEntry::make('consented_at')
                            ->label(__('pondok.applicant.consented_at'))
                            ->dateTime('j M Y, H.i')
                            ->placeholder('-'),
                    ]),
                Section::make(__('pondok.applicant.section_student'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name')
                            ->label(__('pondok.applicant.name')),
                        TextEntry::make('gender')
                            ->label(__('pondok.applicant.gender'))
                            ->formatStateUsing(fn (string $state): string => __("pondok.common.genders.{$state}")),
                        TextEntry::make('birth_place')
                            ->label(__('pondok.applicant.birth_place')),
                        TextEntry::make('birth_date')
                            ->label(__('pondok.applicant.birth_date'))
                            ->date('j M Y'),
                        TextEntry::make('nisn')
                            ->label(__('pondok.applicant.nisn'))
                            ->placeholder('-'),
                        TextEntry::make('previous_school')
                            ->label(__('pondok.applicant.previous_school')),
                    ]),
                Section::make(__('pondok.applicant.section_guardian'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('guardian_name')
                            ->label(__('pondok.applicant.guardian_name')),
                        TextEntry::make('guardian_phone')
                            ->label(__('pondok.applicant.guardian_phone'))
                            ->copyable(),
                        TextEntry::make('email')
                            ->label(__('pondok.applicant.email'))
                            ->placeholder('-'),
                    ]),
                Section::make(__('pondok.applicant.section_invoices'))
                    ->schema([
                        RepeatableEntry::make('invoices')
                            ->hiddenLabel()
                            ->placeholder(__('pondok.applicant.no_invoices'))
                            ->table([
                                TableColumn::make(__('pondok.invoice.invoice_no')),
                                TableColumn::make(__('pondok.invoice.total'))->alignment(Alignment::End),
                                TableColumn::make(__('pondok.invoice.paid_total'))->alignment(Alignment::End),
                                TableColumn::make(__('pondok.invoice.status')),
                            ])
                            ->schema([
                                TextEntry::make('invoice_no')
                                    ->url(fn (Invoice $record): ?string => InvoiceResource::canView($record)
                                        ? InvoiceResource::getUrl('view', ['record' => $record])
                                        : null),
                                TextEntry::make('total')
                                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                                    ->alignEnd()
                                    ->extraAttributes(['class' => 'tabular-nums']),
                                TextEntry::make('paid_total')
                                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                                    ->alignEnd()
                                    ->extraAttributes(['class' => 'tabular-nums']),
                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn (Invoice $record): string => $record->statusLabel())
                                    ->color(fn (Invoice $record): string => $record->statusColor()),
                            ]),
                    ]),
                Section::make(__('pondok.applicant.section_completeness'))
                    ->schema([
                        TextEntry::make('missing_documents')
                            ->label(__('pondok.applicant.missing_documents'))
                            ->state(fn (Applicant $record): array => static::missingRequiredDocuments($record))
                            ->bulleted()
                            ->placeholder(__('pondok.applicant.completeness_ok')),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['unit', 'admissionWave.academicYear', 'admissionTrack']))
            ->columns([
                TextColumn::make('registration_no')
                    ->label(__('pondok.applicant.registration_no'))
                    ->extraAttributes(['class' => 'tabular-nums'])
                    ->searchable(),
                TextColumn::make('name')
                    ->label(__('pondok.applicant.name'))
                    ->searchable(),
                TextColumn::make('unit.name')
                    ->label(__('pondok.applicant.unit')),
                TextColumn::make('admissionWave.name')
                    ->label(__('pondok.applicant.wave'))
                    ->description(fn (Applicant $record): ?string => $record->admissionWave?->academicYear?->name),
                TextColumn::make('admissionTrack.name')
                    ->label(__('pondok.applicant.track'))
                    ->toggleable(),
                TextColumn::make('guardian_phone')
                    ->label(__('pondok.applicant.guardian_phone'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(__('pondok.applicant.registered_at'))
                    ->dateTime('j M Y, H.i')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('pondok.applicant.status'))
                    ->badge()
                    ->formatStateUsing(fn (Applicant $record): string => $record->statusLabel())
                    ->color(fn (Applicant $record): string => $record->statusColor()),
            ])
            ->filters([
                SelectFilter::make('wave')
                    ->label(__('pondok.applicant.wave'))
                    ->relationship('admissionWave', 'name')
                    ->getOptionLabelFromRecordUsing(fn (AdmissionWave $record): string => "{$record->name} — {$record->academicYear?->name}"),
                SelectFilter::make('unit')
                    ->label(__('pondok.applicant.unit'))
                    ->options(fn (): array => Unit::query()->accessible()->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, mixed $unitId) => $query->where('unit_id', $unitId),
                    )),
                SelectFilter::make('status')
                    ->label(__('pondok.applicant.status'))
                    ->options(collect(Applicant::STATUSES)
                        ->mapWithKeys(fn (string $status): array => [$status => __("pondok.applicant.statuses.{$status}")])
                        ->all()),
                SelectFilter::make('track')
                    ->label(__('pondok.applicant.track'))
                    ->options(fn (): array => AdmissionTrack::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, mixed $trackId) => $query->where('admission_track_id', $trackId),
                    )),
            ])
            ->emptyStateIcon(Heroicon::OutlinedUserPlus)
            ->emptyStateHeading(__('pondok.applicant.empty'))
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    static::deleteAction(),
                ]),
            ]);
    }

    /**
     * Membuat tagihan pendaftaran yang tertinggal (mis. tarif baru diatur
     * setelah pendaftar mengirim formulir).
     */
    public static function generateInvoicesAction(): Action
    {
        return Action::make('generateInvoices')
            ->label(__('pondok.applicant.generate_invoices'))
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription(__('pondok.applicant.generate_invoices_description'))
            ->authorize(fn (Applicant $record): bool => static::can('generateInvoices', $record))
            ->visible(fn (Applicant $record): bool => Setting::moduleEnabled('keuangan')
                && $record->isOpen()
                && ! $record->registrationInvoices()->exists())
            ->action(function (Applicant $record, GenerateRegistrationInvoices $generate, Action $action): void {
                try {
                    $result = $generate->handle($record, auth()->user());
                } catch (ValidationException|RuntimeException $exception) {
                    $message = $exception instanceof ValidationException
                        ? collect($exception->errors())->flatten()->first()
                        : $exception->getMessage();

                    Notification::make()->title($message)->danger()->send();
                    $action->halt();

                    return;
                }

                if ($result['created_invoices'] === 0) {
                    Notification::make()->title(__('pondok.applicant.invoices_none_generated'))->warning()->send();

                    return;
                }

                Notification::make()
                    ->title(__('pondok.applicant.invoices_generated', ['count' => $result['created_invoices']]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Token baru ditampilkan sekali lewat notifikasi persisten.
     */
    public static function resetTokenAction(): Action
    {
        return Action::make('resetToken')
            ->label(__('pondok.applicant.reset_token'))
            ->icon(Heroicon::OutlinedKey)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('pondok.applicant.reset_token_description'))
            ->authorize(fn (Applicant $record): bool => static::can('resetToken', $record))
            ->action(function (Applicant $record, ResetApplicantToken $reset): void {
                $token = $reset->handle($record, auth()->user());

                Notification::make()
                    ->title(__('pondok.applicant.token_reset_notice', ['token' => $token]))
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    public static function deleteAction(): Action
    {
        return Action::make('delete')
            ->label(__('pondok.applicant.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('pondok.applicant.delete_description'))
            ->authorize(fn (Applicant $record): bool => static::can('delete', $record))
            ->action(function (Applicant $record, DeleteApplicant $delete, Action $action): void {
                try {
                    $delete->handle($record, auth()->user());
                } catch (ValidationException $exception) {
                    Notification::make()->title(collect($exception->errors())->flatten()->first())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title(__('pondok.applicant.deleted'))->success()->send();
                $action->redirect(static::getUrl('index'));
            });
    }

    /**
     * Error `VerifyApplicantDocument` (mis. catatan wajib) tampil di field modal relation manager.
     */
    public static function verifyShowingFormErrors(Action $action, Closure $run): mixed
    {
        return static::runShowingFormErrors($action, $run);
    }

    /**
     * Nama syarat wajib yang berkasnya belum diunggah atau belum valid.
     *
     * @return list<string>
     */
    public static function missingRequiredDocuments(Applicant $applicant): array
    {
        $valid = ApplicantDocument::withoutGlobalScopes()
            ->where('applicant_id', $applicant->id)
            ->where('status', ApplicantDocument::STATUS_VALID)
            ->pluck('admission_requirement_id')
            ->all();

        return $applicant->requirements()
            ->where('is_required', true)
            ->whereNotIn('id', $valid)
            ->pluck('name')
            ->all();
    }

    public static function getRelations(): array
    {
        return [
            DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApplicants::route('/'),
            'view' => ViewApplicant::route('/{record}'),
        ];
    }
}
