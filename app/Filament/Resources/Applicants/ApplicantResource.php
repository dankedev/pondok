<?php

namespace App\Filament\Resources\Applicants;

use App\Actions\Psb\AcceptApplicant;
use App\Actions\Psb\DeleteApplicant;
use App\Actions\Psb\GenerateAcceptanceInvoices;
use App\Actions\Psb\GenerateRegistrationInvoices;
use App\Actions\Psb\MarkApplicantVerified;
use App\Actions\Psb\RecordApplicantDecision;
use App\Actions\Psb\RejectApplicant;
use App\Actions\Psb\ReopenRejectedApplicant;
use App\Actions\Psb\ReserveApplicant;
use App\Actions\Psb\ResetApplicantToken;
use App\Actions\Psb\ReturnApplicantToSubmitted;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\Applicants\Pages\ListApplicants;
use App\Filament\Resources\Applicants\Pages\ViewApplicant;
use App\Filament\Resources\Applicants\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\AdmissionTrack;
use App\Models\AdmissionWave;
use App\Models\Applicant;
use App\Models\FeeOverride;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Unit;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
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
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Daftar dan detail pendaftar PSB. Pendaftar dibuat lewat halaman publik
 * (`SubmitApplication`), jadi tanpa Create dan Edit. Hapus hanya untuk spam
 * atau pendaftaran ganda lewat `DeleteApplicant`.
 *
 * Keputusan seleksi (F011) lewat aksi header `ViewApplicant`; setiap aksi
 * memanggil Action PSB dan hanya tampil untuk status yang berlaku.
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
                Section::make(__('pondok.applicant.section_decision'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('reviewer.name')
                            ->label(__('pondok.applicant.reviewer'))
                            ->placeholder('-'),
                        TextEntry::make('reviewed_at')
                            ->label(__('pondok.applicant.reviewed_at'))
                            ->dateTime('j M Y, H.i')
                            ->placeholder('-'),
                        TextEntry::make('review_note')
                            ->label(__('pondok.applicant.review_note'))
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
                        RepeatableEntry::make('activeInvoices')
                            ->hiddenLabel()
                            ->state(fn (Applicant $record): Collection => $record->invoices
                                ->where('status', '<>', Invoice::STATUS_CANCELLED)
                                ->sortBy([['period', 'asc'], ['id', 'asc']])
                                ->values())
                            ->placeholder(__('pondok.applicant.no_invoices'))
                            ->table([
                                TableColumn::make(__('pondok.invoice.invoice_no')),
                                TableColumn::make(__('pondok.invoice.total'))->alignment(Alignment::End),
                                TableColumn::make(__('pondok.invoice.paid_total'))->alignment(Alignment::End),
                                TableColumn::make(__('pondok.applicant.invoice_remaining'))->alignment(Alignment::End),
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
                                TextEntry::make('remaining')
                                    ->state(fn (Invoice $record): int => $record->remainingAmount())
                                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                                    ->alignEnd()
                                    ->extraAttributes(['class' => 'tabular-nums']),
                                TextEntry::make('status')
                                    ->badge()
                                    ->formatStateUsing(fn (Invoice $record): string => $record->statusLabel())
                                    ->color(fn (Invoice $record): string => $record->statusColor()),
                            ]),
                    ]),
                Section::make(__('pondok.applicant.section_overrides'))
                    ->visible(fn (Applicant $record): bool => $record->feeOverrides->isNotEmpty())
                    ->schema([
                        RepeatableEntry::make('feeOverrides')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make(__('pondok.applicant.override_fee_type')),
                                TableColumn::make(__('pondok.applicant.override_value')),
                                TableColumn::make(__('pondok.applicant.override_source')),
                                TableColumn::make(__('pondok.applicant.override_period')),
                            ])
                            ->schema([
                                TextEntry::make('feeType.name'),
                                TextEntry::make('value')
                                    ->formatStateUsing(fn (FeeOverride $record): string => $record->valueLabel()),
                                TextEntry::make('scholarship.name')
                                    ->placeholder(__('pondok.applicant.override_custom')),
                                TextEntry::make('valid_from')
                                    ->formatStateUsing(fn (FeeOverride $record): string => __('pondok.applicant.override_period_value', [
                                        'from' => $record->valid_from->format('j M Y'),
                                        'until' => $record->valid_until?->format('j M Y') ?? __('pondok.applicant.override_open_ended'),
                                    ])),
                            ]),
                    ]),
                Section::make(__('pondok.applicant.section_completeness'))
                    ->schema([
                        TextEntry::make('missing_documents')
                            ->label(__('pondok.applicant.missing_documents'))
                            ->state(fn (Applicant $record): array => $record->missingRequiredDocuments()->pluck('name')->all())
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
                TextColumn::make('reviewed_at')
                    ->label(__('pondok.applicant.reviewed_at'))
                    ->dateTime('j M Y, H.i')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
     * Aksi keputusan seleksi untuk header `ViewApplicant` (F011).
     *
     * @return list<Action>
     */
    public static function decisionActions(): array
    {
        return [
            static::decisionAction('verify', __('pondok.applicant.verify'), 'info', Heroicon::OutlinedCheckBadge, Applicant::STATUS_VERIFIED, [Applicant::STATUS_SUBMITTED])
                ->modalDescription(__('pondok.applicant.verify_description'))
                ->action(fn (Applicant $record, array $data, MarkApplicantVerified $verify, Action $action) => static::decide(
                    $action,
                    $record,
                    fn () => $verify->handle($record, auth()->user(), $data['review_note'] ?? null),
                )),
            static::decisionAction('accept', __('pondok.applicant.accept'), 'success', Heroicon::OutlinedCheckCircle, Applicant::STATUS_ACCEPTED, [Applicant::STATUS_VERIFIED, Applicant::STATUS_RESERVE])
                ->modalDescription(fn (Applicant $record): string => static::acceptDescription($record))
                ->action(function (Applicant $record, array $data, AcceptApplicant $accept, Action $action): void {
                    $result = static::decide($action, $record, fn () => $accept->handle($record, auth()->user(), $data['review_note'] ?? null), notify: false);

                    if ($result !== null) {
                        static::notifyAccepted($record, $result);
                    }
                }),
            static::decisionAction('reserve', __('pondok.applicant.reserve'), 'warning', Heroicon::OutlinedClock, Applicant::STATUS_RESERVE, [Applicant::STATUS_VERIFIED], noteRequired: true)
                ->modalDescription(__('pondok.applicant.reserve_description'))
                ->action(fn (Applicant $record, array $data, ReserveApplicant $reserve, Action $action) => static::decide(
                    $action,
                    $record,
                    fn () => $reserve->handle($record, auth()->user(), (string) ($data['review_note'] ?? '')),
                )),
            static::decisionAction('return', __('pondok.applicant.return'), 'gray', Heroicon::OutlinedArrowUturnLeft, Applicant::STATUS_SUBMITTED, [Applicant::STATUS_VERIFIED], noteRequired: true)
                ->modalDescription(__('pondok.applicant.return_description'))
                ->action(fn (Applicant $record, array $data, ReturnApplicantToSubmitted $return, Action $action) => static::decide(
                    $action,
                    $record,
                    fn () => $return->handle($record, auth()->user(), (string) ($data['review_note'] ?? '')),
                )),
            static::decisionAction('reject', __('pondok.applicant.reject'), 'danger', Heroicon::OutlinedXCircle, Applicant::STATUS_REJECTED, [Applicant::STATUS_SUBMITTED, Applicant::STATUS_VERIFIED, Applicant::STATUS_RESERVE], noteRequired: true)
                ->modalDescription(__('pondok.applicant.reject_description'))
                ->action(fn (Applicant $record, array $data, RejectApplicant $reject, Action $action) => static::decide(
                    $action,
                    $record,
                    fn () => $reject->handle($record, auth()->user(), (string) ($data['review_note'] ?? '')),
                )),
            static::decisionAction('cancelAcceptance', __('pondok.applicant.cancel_acceptance'), 'danger', Heroicon::OutlinedXCircle, Applicant::STATUS_REJECTED, [Applicant::STATUS_ACCEPTED], noteRequired: true)
                ->modalDescription(__('pondok.applicant.cancel_acceptance_description'))
                ->action(fn (Applicant $record, array $data, RejectApplicant $reject, Action $action) => static::decide(
                    $action,
                    $record,
                    fn () => $reject->handle($record, auth()->user(), (string) ($data['review_note'] ?? '')),
                )),
            static::decisionAction('reopen', __('pondok.applicant.reopen'), 'gray', Heroicon::OutlinedArrowPath, Applicant::STATUS_SUBMITTED, [Applicant::STATUS_REJECTED], noteRequired: true, ability: 'reopen')
                ->modalDescription(__('pondok.applicant.reopen_description'))
                ->action(fn (Applicant $record, array $data, ReopenRejectedApplicant $reopen, Action $action) => static::decide(
                    $action,
                    $record,
                    fn () => $reopen->handle($record, auth()->user(), (string) ($data['review_note'] ?? '')),
                    message: __('pondok.applicant.reopened'),
                )),
        ];
    }

    /**
     * Kerangka aksi keputusan: modal konfirmasi dengan catatan, tampil hanya
     * bila user boleh memutuskan dan status pendaftar termasuk `$from`.
     *
     * @param  list<string>  $from
     */
    protected static function decisionAction(string $name, string $label, string $color, Heroicon $icon, string $to, array $from, bool $noteRequired = false, string $ability = 'decide'): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->modalIcon($icon)
            ->modalSubmitActionLabel($label)
            ->authorize(fn (Applicant $record): bool => static::can($ability, $record))
            ->visible(fn (Applicant $record): bool => in_array($record->status, $from, true) && $record->canTransitionTo($to))
            ->schema([
                Textarea::make('review_note')
                    ->label(__('pondok.applicant.review_note'))
                    ->helperText(__('pondok.applicant.review_note_helper'))
                    ->required($noteRequired)
                    ->maxLength(RecordApplicantDecision::NOTE_MAX_LENGTH)
                    ->rows(3),
            ]);
    }

    /**
     * Jalankan Action keputusan. Galat catatan tampil di field modal; galat
     * lain (status berubah, berkas belum lengkap, ...) lewat notifikasi.
     * Setelah berhasil, data infolist dimuat ulang.
     */
    protected static function decide(Action $action, Applicant $record, Closure $run, bool $notify = true, ?string $message = null): mixed
    {
        try {
            $result = static::runShowingFormErrors($action, function () use ($run) {
                try {
                    return $run();
                } catch (ValidationException $exception) {
                    if (array_key_exists('review_note', $exception->errors())) {
                        throw $exception;
                    }

                    Notification::make()
                        ->title(collect($exception->errors())->flatten()->first())
                        ->danger()
                        ->send();

                    return null;
                }
            });
        } finally {
            static::refreshRecord($record);
        }

        if ($result === null) {
            $action->halt();

            return null;
        }

        if ($notify) {
            Notification::make()->title($message ?? __('pondok.applicant.decided'))->success()->send();
        }

        return $result;
    }

    /**
     * @param  array{applicant: Applicant, billing: array<string, mixed>|null, billing_failed: bool}  $result
     */
    protected static function notifyAccepted(Applicant $record, array $result): void
    {
        if ($result['billing_failed']) {
            Notification::make()->title(__('pondok.applicant.billing_failed'))->warning()->persistent()->send();

            return;
        }

        if ($result['billing'] === null) {
            Notification::make()->title(__('pondok.applicant.accepted'))->success()->send();

            return;
        }

        $applied = count($result['billing']['scholarship']['created']);

        Notification::make()
            ->title(__('pondok.applicant.accepted_with_invoices', ['count' => $result['billing']['created_invoices']]))
            ->body($applied > 0 ? __('pondok.applicant.scholarship_applied', [
                'scholarship' => $record->admissionTrack?->scholarship?->name,
                'count' => $applied,
            ]) : null)
            ->success()
            ->send();

        static::notifyMissingRates($result['billing']['skipped']);
    }

    /**
     * @param  list<array{fee_type_id: int|null, reason: string}>  $skipped
     */
    protected static function notifyMissingRates(array $skipped): void
    {
        $ids = collect($skipped)->where('reason', GenerateAcceptanceInvoices::REASON_MISSING_RATE)->pluck('fee_type_id');

        if ($ids->isEmpty()) {
            return;
        }

        Notification::make()
            ->title(__('pondok.applicant.missing_rates', [
                'fee_types' => FeeType::query()->whereKey($ids)->orderBy('priority')->orderBy('id')->pluck('name')->implode(', '),
            ]))
            ->warning()
            ->send();
    }

    protected static function acceptDescription(Applicant $record): string
    {
        $scholarship = $record->admissionTrack?->scholarship;

        return trim(__('pondok.applicant.accept_description').' '.($scholarship === null
            ? ''
            : __('pondok.applicant.accept_scholarship_description', ['scholarship' => $scholarship->name])));
    }

    /**
     * Membuat beasiswa jalur dan tagihan penerimaan yang tertinggal (keuangan
     * baru diaktifkan, tarif baru diatur, atau pembuatan sebelumnya gagal).
     */
    public static function generateAcceptanceInvoicesAction(): Action
    {
        return Action::make('generateAcceptanceInvoices')
            ->label(__('pondok.applicant.generate_acceptance_invoices'))
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription(__('pondok.applicant.generate_acceptance_invoices_description'))
            ->authorize(fn (Applicant $record): bool => static::can('generateInvoices', $record))
            ->visible(fn (Applicant $record): bool => Setting::moduleEnabled('keuangan')
                && $record->status === Applicant::STATUS_ACCEPTED
                && GenerateAcceptanceInvoices::hasUnbilledGroups($record))
            ->action(function (Applicant $record, GenerateAcceptanceInvoices $generate, Action $action): void {
                try {
                    $result = $generate->handle($record, auth()->user());
                } catch (ValidationException|RuntimeException $exception) {
                    $message = $exception instanceof ValidationException
                        ? collect($exception->errors())->flatten()->first()
                        : $exception->getMessage();

                    Notification::make()->title($message)->danger()->send();
                    static::refreshRecord($record);
                    $action->halt();

                    return;
                }

                static::refreshRecord($record);

                if ($result['created_invoices'] === 0) {
                    Notification::make()->title(__('pondok.applicant.acceptance_invoices_none_generated'))->warning()->send();

                    return;
                }

                Notification::make()
                    ->title(__('pondok.applicant.acceptance_invoices_generated', ['count' => $result['created_invoices']]))
                    ->success()
                    ->send();

                static::notifyMissingRates($result['skipped']);
            });
    }

    /**
     * Relasi yang dipakai infolist `ViewApplicant`.
     *
     * @return array<int|string, mixed>
     */
    public static function infolistRelations(): array
    {
        return [
            'unit',
            'reviewer',
            'admissionWave.academicYear',
            'admissionTrack.scholarship',
            'invoices' => fn ($query) => $query->withoutGlobalScopes()->orderBy('period')->orderBy('id'),
            'feeOverrides' => fn ($query) => $query->with(['feeType', 'scholarship'])->orderBy('valid_from')->orderBy('id'),
        ];
    }

    protected static function refreshRecord(Applicant $record): void
    {
        $record->refresh()->load(static::infolistRelations());
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
