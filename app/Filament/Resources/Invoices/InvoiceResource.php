<?php

namespace App\Filament\Resources\Invoices;

use App\Actions\Keuangan\CancelInvoice;
use App\Actions\Keuangan\GenerateAnnualInvoices;
use App\Actions\Keuangan\GenerateMonthlyInvoices;
use App\Actions\Keuangan\RecordPayment;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoices\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Payments\PaymentResource;
use App\Jobs\Keuangan\GenerateInvoicesJob;
use App\Models\AcademicYear;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Unit;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Daftar dan detail tagihan. Tagihan dibuat generator (`GenerateMonthlyInvoices`)
 * dan dibatalkan lewat `CancelInvoice`; tidak ada Create, Edit, maupun Delete.
 *
 * Cakupan jenjang mengikuti global scope `Invoice` (lewat santri).
 */
class InvoiceResource extends Resource
{
    use BelongsToModule, ShowsActionErrorsOnForm;

    protected static ?string $model = Invoice::class;

    // Design guide §3: kapital hanya di awal kalimat.
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 0;

    protected static ?string $recordTitleAttribute = 'invoice_no';

    public static function getModule(): string
    {
        return 'keuangan';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.invoice.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.invoice.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('pondok.navigation.keuangan');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('pondok.invoice.section_summary'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('invoice_no')
                            ->label(__('pondok.invoice.invoice_no')),
                        TextEntry::make('student')
                            ->label(__('pondok.invoice.student'))
                            ->state(fn (Invoice $record): string => static::ownerLabel($record)),
                        TextEntry::make('unit')
                            ->label(__('pondok.invoice.unit'))
                            ->state(fn (Invoice $record): ?string => $record->student?->unit?->name ?? $record->applicant?->unit?->name)
                            ->placeholder('-'),
                        TextEntry::make('academicYear.name')
                            ->label(__('pondok.invoice.academic_year')),
                        TextEntry::make('period')
                            ->label(__('pondok.invoice.period'))
                            ->formatStateUsing(fn (CarbonInterface $state): string => static::periodLabel($state)),
                        TextEntry::make('billing_group')
                            ->label(__('pondok.invoice.billing_group')),
                        TextEntry::make('due_on')
                            ->label(__('pondok.invoice.due_on'))
                            ->date('j M Y'),
                        TextEntry::make('status')
                            ->label(__('pondok.invoice.status'))
                            ->badge()
                            ->formatStateUsing(fn (Invoice $record): string => $record->statusLabel())
                            ->color(fn (Invoice $record): string => $record->statusColor()),
                        TextEntry::make('generator.name')
                            ->label(__('pondok.invoice.generated_by'))
                            ->placeholder(__('pondok.invoice.generated_by_system')),
                        TextEntry::make('total')
                            ->label(__('pondok.invoice.total'))
                            ->money('IDR', locale: 'id', decimalPlaces: 0),
                        TextEntry::make('paid_total')
                            ->label(__('pondok.invoice.paid_total'))
                            ->money('IDR', locale: 'id', decimalPlaces: 0),
                    ]),
                Section::make(__('pondok.invoice.section_items'))
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make(__('pondok.invoice.description')),
                                TableColumn::make(__('pondok.invoice.base_amount'))->alignment(Alignment::End),
                                TableColumn::make(__('pondok.invoice.discount_amount'))->alignment(Alignment::End),
                                TableColumn::make(__('pondok.invoice.amount'))->alignment(Alignment::End),
                                TableColumn::make(__('pondok.payment.paid_amount'))->alignment(Alignment::End),
                                TableColumn::make(__('pondok.invoice.rate_source')),
                                TableColumn::make(__('pondok.invoice.fee_override')),
                            ])
                            ->schema([
                                TextEntry::make('description'),
                                TextEntry::make('base_amount')
                                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                                    ->alignEnd()
                                    ->extraAttributes(['class' => 'tabular-nums']),
                                TextEntry::make('discount_amount')
                                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                                    ->alignEnd()
                                    ->extraAttributes(['class' => 'tabular-nums']),
                                TextEntry::make('amount')
                                    ->formatStateUsing(fn (InvoiceItem $record): string => static::itemAmountLabel($record))
                                    ->alignEnd()
                                    ->extraAttributes(['class' => 'tabular-nums']),
                                TextEntry::make('paid_amount')
                                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                                    ->alignEnd()
                                    ->extraAttributes(['class' => 'tabular-nums']),
                                TextEntry::make('rate_source')
                                    ->formatStateUsing(fn (string $state): string => __("pondok.invoice.rate_sources.{$state}")),
                                TextEntry::make('fee_override_id')
                                    ->state(fn (InvoiceItem $record): string => $record->feeOverride === null
                                        ? __('pondok.invoice.no_override')
                                        : "{$record->feeOverride->valueLabel()} · {$record->feeOverride->reason}"),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('period')->orderBy('invoice_no'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['student.unit', 'applicant']))
            ->columns([
                TextColumn::make('invoice_no')
                    ->label(__('pondok.invoice.invoice_no'))
                    ->searchable(),
                // Tagihan pendaftar (tanpa santri) menampilkan nomor pendaftaran dan nama pendaftar.
                TextColumn::make('student.nis')
                    ->label(__('pondok.invoice.nis'))
                    ->state(fn (Invoice $record): ?string => $record->student?->nis ?? $record->applicant?->registration_no)
                    ->searchable(query: fn (Builder $query, string $search): Builder => static::searchOwner($query, 'nis', 'registration_no', $search)),
                TextColumn::make('student.name')
                    ->label(__('pondok.invoice.student_name'))
                    ->state(fn (Invoice $record): ?string => $record->student?->name ?? $record->applicant?->name)
                    ->searchable(query: fn (Builder $query, string $search): Builder => static::searchOwner($query, 'name', 'name', $search)),
                TextColumn::make('period')
                    ->label(__('pondok.invoice.period'))
                    ->formatStateUsing(fn (CarbonInterface $state): string => static::periodLabel($state))
                    ->sortable(),
                TextColumn::make('billing_group')
                    ->label(__('pondok.invoice.billing_group')),
                TextColumn::make('total')
                    ->label(__('pondok.invoice.total'))
                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums'])
                    ->sortable(),
                TextColumn::make('paid_total')
                    ->label(__('pondok.invoice.paid_total'))
                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums'])
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('due_on')
                    ->label(__('pondok.invoice.due_on'))
                    ->date('j M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label(__('pondok.invoice.status'))
                    ->badge()
                    ->formatStateUsing(fn (Invoice $record): string => $record->statusLabel())
                    ->color(fn (Invoice $record): string => $record->statusColor()),
            ])
            ->filters([
                SelectFilter::make('period')
                    ->label(__('pondok.invoice.period'))
                    ->options(fn (): array => Invoice::query()
                        ->select('period')
                        ->distinct()
                        ->orderByDesc('period')
                        ->get()
                        ->mapWithKeys(fn (Invoice $invoice): array => [$invoice->period->toDateString() => static::periodLabel($invoice->period)])
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $period) => $query->whereDate('period', $period),
                    )),
                SelectFilter::make('unit')
                    ->label(__('pondok.invoice.unit'))
                    ->options(fn (): array => Unit::query()->accessible()->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, mixed $unitId) => static::whereOwnerUnit($query, $unitId),
                    )),
                SelectFilter::make('status')
                    ->label(__('pondok.invoice.status'))
                    ->options(collect(Invoice::STATUSES)
                        ->mapWithKeys(fn (string $status): array => [$status => __("pondok.invoice.statuses.{$status}")])
                        ->all()),
                SelectFilter::make('billing_group')
                    ->label(__('pondok.invoice.billing_group'))
                    ->options(fn (): array => Invoice::query()
                        ->distinct()
                        ->orderBy('billing_group')
                        ->pluck('billing_group', 'billing_group')
                        ->all()),
            ])
            ->emptyStateIcon(Heroicon::OutlinedBanknotes)
            ->emptyStateHeading(__('pondok.invoice.empty'))
            ->emptyStateDescription(__('pondok.invoice.empty_description'))
            ->emptyStateActions([
                static::generateAction(),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    static::recordPaymentAction(),
                    static::cancelAction(),
                ]),
            ]);
    }

    /**
     * Mengantrekan generator untuk satu bulan dalam tahun ajaran aktif, untuk semua jenjang (`GenerateInvoicesJob`).
     */
    public static function generateAction(): Action
    {
        return Action::make('generate')
            ->label(__('pondok.invoice.generate_action'))
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(__('pondok.invoice.generate_heading'))
            ->modalDescription(__('pondok.invoice.generate_description'))
            ->modalSubmitActionLabel(__('pondok.invoice.generate_submit'))
            ->authorize(fn (): bool => static::can('generate'))
            ->schema([
                Select::make('period')
                    ->label(__('pondok.invoice.generate_period'))
                    ->options(fn (): array => static::periodOptions())
                    ->default(fn (): string => today()->startOfMonth()->toDateString())
                    ->required(),
            ])
            ->action(function (array $data, GenerateMonthlyInvoices $generate, Action $action): void {
                $period = CarbonImmutable::parse($data['period'], 'Asia/Jakarta');

                static::runShowingFormErrors($action, fn () => $generate->academicYearFor($period));

                dispatch(GenerateInvoicesJob::monthly(auth()->user(), $period));

                static::notifyGenerateQueued(static::periodLabel($period));
            });
    }

    /**
     * Mengantrekan generator tagihan tahunan untuk satu tahun ajaran, untuk semua jenjang (`GenerateInvoicesJob`).
     */
    public static function generateAnnualAction(): Action
    {
        return Action::make('generateAnnual')
            ->label(__('pondok.invoice.annual_generate_action'))
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('pondok.invoice.annual_generate_heading'))
            ->modalDescription(fn (): string => ($month = GenerateAnnualInvoices::billingMonth()) !== null
                ? __('pondok.invoice.annual_generate_description', ['month' => CarbonImmutable::create(2000, $month)->translatedFormat('F')])
                : __('pondok.invoice.errors.annual_month_not_set'))
            ->modalSubmitActionLabel(__('pondok.invoice.generate_submit'))
            ->authorize(fn (): bool => static::can('generate'))
            ->schema([
                Select::make('academic_year_id')
                    ->label(__('pondok.common.academic_year'))
                    ->options(fn (): array => AcademicYear::query()->orderByDesc('starts_on')->pluck('name', 'id')->all())
                    ->default(fn (): ?int => AcademicYear::current()?->id)
                    ->required(),
            ])
            ->action(function (array $data, GenerateAnnualInvoices $generate, Action $action): void {
                $year = AcademicYear::query()->findOrFail($data['academic_year_id']);

                static::runShowingFormErrors($action, fn () => $generate->billingPeriod($year));

                dispatch(GenerateInvoicesJob::annual(auth()->user(), $year));

                static::notifyGenerateQueued($year->name);
            });
    }

    /**
     * Prasyarat generate sudah lolos dan Job diantrekan; hasilnya dikirim Job sebagai notifikasi database.
     */
    private static function notifyGenerateQueued(string $period): void
    {
        Notification::make()
            ->title(__('pondok.invoice.generate_queued', ['period' => $period]))
            ->body(__('pondok.invoice.generate_queued_body'))
            ->success()
            ->send();
    }

    /**
     * Mencatat pembayaran tunai atau transfer lewat `RecordPayment`; tersembunyi
     * untuk tagihan `lunas` dan `batal`.
     */
    public static function recordPaymentAction(): Action
    {
        return Action::make('recordPayment')
            ->label(__('pondok.payment.record_action'))
            ->icon(Heroicon::OutlinedReceiptPercent)
            ->color('primary')
            ->modalHeading(__('pondok.payment.record_heading'))
            ->modalDescription(__('pondok.payment.record_description'))
            ->modalSubmitActionLabel(__('pondok.payment.record_submit'))
            // Mencegah kirim ganda: tombol simpan nonaktif selama permintaan berjalan.
            ->modalSubmitAction(fn (Action $action): Action => $action->extraAttributes(['wire:loading.attr' => 'disabled'], merge: true))
            ->authorize(fn (): bool => (bool) auth()->user()?->can('create', Payment::class))
            ->hidden(fn (Invoice $record): bool => in_array($record->status, [Invoice::STATUS_PAID, Invoice::STATUS_CANCELLED], true))
            ->fillForm(fn (Invoice $record): array => [
                'amount' => $record->remainingAmount(),
                'paid_on' => today()->toDateString(),
                'method' => Payment::METHOD_CASH,
            ])
            ->schema([
                TextInput::make('amount')
                    ->label(__('pondok.payment.amount'))
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->prefix('Rp')
                    ->required()
                    ->helperText(fn (Invoice $record): string => __('pondok.payment.remaining_helper', [
                        'amount' => Payment::formatRupiah($record->remainingAmount()),
                    ])),
                DatePicker::make('paid_on')
                    ->label(__('pondok.payment.paid_on'))
                    ->native(false)
                    ->displayFormat('j M Y')
                    ->maxDate(fn (): string => today()->toDateString())
                    ->required(),
                ToggleButtons::make('method')
                    ->label(__('pondok.payment.method'))
                    ->options(collect(Payment::MANUAL_METHODS)
                        ->mapWithKeys(fn (string $method): array => [$method => __("pondok.payment.methods.{$method}")])
                        ->all())
                    ->inline()
                    ->required()
                    ->live(),
                FileUpload::make('proof')
                    ->label(__('pondok.payment.proof'))
                    ->helperText(__('pondok.payment.proof_helper'))
                    ->visible(fn (Get $get): bool => $get('method') === Payment::METHOD_TRANSFER)
                    ->storeFiles(false)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                    ->maxSize(RecordPayment::PROOF_MAX_KILOBYTES),
                Textarea::make('note')
                    ->label(__('pondok.payment.note'))
                    ->helperText(__('pondok.payment.note_helper'))
                    ->maxLength(RecordPayment::NOTE_MAX_LENGTH)
                    ->rows(2),
            ])
            ->action(function (Invoice $record, array $data, RecordPayment $recordPayment, Action $action): void {
                $proof = $data['proof'] ?? null;

                if (is_array($proof)) {
                    $proof = collect($proof)->first(fn (mixed $file): bool => $file instanceof UploadedFile);
                }

                try {
                    $payment = $recordPayment->handle($record, auth()->user(), [
                        'amount' => $data['amount'] ?? null,
                        'paid_on' => $data['paid_on'] ?? null,
                        'method' => $data['method'] ?? null,
                        'proof' => ($data['method'] ?? null) === Payment::METHOD_TRANSFER ? $proof : null,
                        'note' => $data['note'] ?? null,
                    ]);
                } catch (ValidationException $exception) {
                    // Tagihan dibatalkan atau dilunasi di tempat lain: bukan kesalahan isian form.
                    if (array_key_exists('invoice', $exception->errors())) {
                        Notification::make()->title($exception->errors()['invoice'][0])->danger()->send();
                        $action->cancel();

                        return;
                    }

                    static::runShowingFormErrors($action, fn () => throw $exception);

                    return;
                } catch (RuntimeException $exception) {
                    if ($exception->getMessage() !== __('pondok.payment.errors.receipt_number_conflict')) {
                        throw $exception;
                    }

                    Notification::make()->title($exception->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title(__('pondok.payment.recorded', [
                        'amount' => Payment::formatRupiah($payment->amount),
                        'receipt_no' => $payment->receipt_no,
                    ]))
                    ->success()
                    ->actions([
                        Action::make('printReceipt')
                            ->label(__('pondok.payment.print_action'))
                            ->url(PaymentResource::receiptUrl($payment), shouldOpenInNewTab: true),
                    ])
                    ->send();
            });
    }

    /**
     * Membatalkan tagihan yang belum dibayar; tersembunyi bila tidak bisa dibatalkan.
     */
    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('pondok.invoice.cancel_action'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('pondok.invoice.cancel_heading'))
            ->modalDescription(__('pondok.invoice.cancel_description'))
            ->modalSubmitActionLabel(__('pondok.invoice.cancel_submit'))
            ->authorize(fn (Invoice $record): bool => static::can('cancel', $record))
            ->hidden(fn (Invoice $record): bool => ! $record->isCancellable())
            ->schema([
                TextInput::make('reason')
                    ->label(__('pondok.invoice.cancel_reason'))
                    ->helperText(__('pondok.invoice.cancel_reason_helper'))
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (Invoice $record, array $data, CancelInvoice $cancel, Action $action): void {
                try {
                    $cancel->handle($record, auth()->user(), (string) $data['reason']);
                } catch (ValidationException $exception) {
                    // Tagihan sudah dibatalkan atau dibayar di tempat lain: bukan kesalahan isian form.
                    if (array_key_exists('invoice', $exception->errors())) {
                        Notification::make()->title($exception->errors()['invoice'][0])->danger()->send();
                        $action->cancel();

                        return;
                    }

                    static::runShowingFormErrors($action, fn () => throw $exception);
                }

                Notification::make()
                    ->title(__('pondok.invoice.cancelled', ['invoice_no' => $record->invoice_no]))
                    ->success()
                    ->send();
            });
    }

    /**
     * "NIS — Nama" untuk tagihan santri, "Pendaftar: PSB-… — Nama" untuk tagihan pendaftar.
     */
    public static function ownerLabel(Invoice $invoice): string
    {
        if ($invoice->student === null && $invoice->applicant !== null) {
            return __('pondok.invoice.applicant_owner', [
                'number' => $invoice->applicant->registration_no,
                'name' => $invoice->applicant->name,
            ]);
        }

        return "{$invoice->student?->nis} — {$invoice->student?->name}";
    }

    /**
     * Mencari tagihan lewat kolom santri atau, untuk tagihan tanpa santri, kolom pendaftar.
     * Dipakai juga oleh `PaymentResource` dengan relasi berawalan `invoice.`.
     */
    public static function searchOwner(Builder $query, string $studentColumn, string $applicantColumn, string $search, string $prefix = ''): Builder
    {
        return $query
            ->whereHas("{$prefix}student", fn (Builder $query) => $query->whereLike($studentColumn, "%{$search}%"))
            ->orWhereHas("{$prefix}applicant", fn (Builder $query) => $query->whereLike($applicantColumn, "%{$search}%"));
    }

    /**
     * Tagihan santri jenjang `$unitId` atau tagihan pendaftar jenjang `$unitId`.
     */
    public static function whereOwnerUnit(Builder $query, mixed $unitId, string $prefix = ''): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereHas("{$prefix}student", fn (Builder $query) => $query->where('unit_id', $unitId))
            ->orWhereHas("{$prefix}applicant", fn (Builder $query) => $query->where('unit_id', $unitId)));
    }

    public static function periodLabel(CarbonInterface $period): string
    {
        return $period->locale(app()->getLocale())->translatedFormat('F Y');
    }

    /**
     * Bulan-bulan dalam tahun ajaran aktif; tanpa tahun ajaran aktif, bulan berjalan saja.
     *
     * @return array<string, string>
     */
    public static function periodOptions(): array
    {
        $year = AcademicYear::current();
        $start = CarbonImmutable::parse(($year?->starts_on ?? today())->toDateString(), 'Asia/Jakarta')->startOfMonth();
        $end = CarbonImmutable::parse(($year?->ends_on ?? today())->toDateString(), 'Asia/Jakarta')->startOfMonth();

        $options = [];

        for ($month = $start; $month->lte($end); $month = $month->addMonth()) {
            $options[$month->toDateString()] = static::periodLabel($month);
        }

        return $options;
    }

    /**
     * Jumlah item; Rp 0 karena keringanan nominal 0 ditulis "Gratis".
     */
    public static function itemAmountLabel(InvoiceItem $item): string
    {
        if ($item->amount === 0 && $item->feeOverride?->isFree()) {
            return __('pondok.invoice.free');
        }

        return (string) Number::currency($item->amount, in: 'IDR', locale: 'id', precision: 0);
    }

    public static function getRelations(): array
    {
        return [
            PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'view' => ViewInvoice::route('/{record}'),
        ];
    }
}
