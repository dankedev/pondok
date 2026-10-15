<?php

namespace App\Filament\Resources\Payments;

use App\Actions\Keuangan\CancelPayment;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Unit;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
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
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Daftar dan detail pembayaran. Pembayaran dicatat dari halaman tagihan
 * (`RecordPayment`) dan dibatalkan lewat `CancelPayment`; tidak ada Create,
 * Edit, maupun Delete.
 *
 * Cakupan jenjang mengikuti global scope `Payment` (lewat tagihan, lalu santri).
 */
class PaymentResource extends Resource
{
    use BelongsToModule, ShowsActionErrorsOnForm;

    protected static ?string $model = Payment::class;

    // Design guide §3: kapital hanya di awal kalimat.
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'receipt_no';

    public static function getModule(): string
    {
        return 'keuangan';
    }

    public static function getModelLabel(): string
    {
        return __('pondok.payment.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pondok.payment.plural');
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
                Section::make(__('pondok.payment.section_summary'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('receipt_no')
                            ->label(__('pondok.payment.receipt_no')),
                        TextEntry::make('status')
                            ->label(__('pondok.payment.status'))
                            ->badge()
                            ->formatStateUsing(fn (Payment $record): string => $record->statusLabel())
                            ->color(fn (Payment $record): string => $record->statusColor()),
                        TextEntry::make('paid_on')
                            ->label(__('pondok.payment.paid_on'))
                            ->date('j M Y'),
                        TextEntry::make('method')
                            ->label(__('pondok.payment.method'))
                            ->formatStateUsing(fn (Payment $record): string => $record->methodLabel()),
                        TextEntry::make('amount')
                            ->label(__('pondok.payment.amount'))
                            ->money('IDR', locale: 'id', decimalPlaces: 0)
                            ->extraAttributes(['class' => 'tabular-nums']),
                        TextEntry::make('amount_in_words')
                            ->label(__('pondok.payment.in_words'))
                            ->state(fn (Payment $record): string => $record->amountInWords()),
                        TextEntry::make('invoice.invoice_no')
                            ->label(__('pondok.payment.invoice'))
                            ->url(fn (Payment $record): ?string => $record->invoice !== null && InvoiceResource::canView($record->invoice)
                                ? InvoiceResource::getUrl('view', ['record' => $record->invoice])
                                : null),
                        TextEntry::make('student')
                            ->label(__('pondok.payment.student'))
                            ->state(fn (Payment $record): ?string => static::studentLabel($record))
                            ->placeholder('-'),
                        TextEntry::make('invoice.student.unit.name')
                            ->label(__('pondok.payment.unit'))
                            ->placeholder('-'),
                        TextEntry::make('invoice.period')
                            ->label(__('pondok.payment.period'))
                            ->formatStateUsing(fn (CarbonInterface $state): string => InvoiceResource::periodLabel($state)),
                        TextEntry::make('receiver.name')
                            ->label(__('pondok.payment.received_by'))
                            ->placeholder('-'),
                        TextEntry::make('created_at')
                            ->label(__('pondok.payment.recorded_at'))
                            ->dateTime('j M Y, H.i'),
                        TextEntry::make('note')
                            ->label(__('pondok.payment.note'))
                            ->placeholder('-')
                            ->columnSpanFull(),
                    ]),
                Section::make(__('pondok.payment.section_cancellation'))
                    ->columns(3)
                    ->visible(fn (Payment $record): bool => $record->status === Payment::STATUS_CANCELLED)
                    ->schema([
                        TextEntry::make('cancelled_at')
                            ->label(__('pondok.payment.cancelled_at'))
                            ->dateTime('j M Y, H.i'),
                        TextEntry::make('canceller.name')
                            ->label(__('pondok.payment.cancelled_by'))
                            ->placeholder('-'),
                        TextEntry::make('cancel_reason')
                            ->label(__('pondok.payment.cancel_reason')),
                    ]),
                Section::make(__('pondok.payment.section_allocations'))
                    ->schema([
                        RepeatableEntry::make('allocations')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make(__('pondok.payment.allocation_item')),
                                TableColumn::make(__('pondok.payment.allocation_amount'))->alignment(Alignment::End),
                            ])
                            ->schema([
                                TextEntry::make('invoiceItem.description'),
                                TextEntry::make('amount')
                                    ->state(fn (PaymentAllocation $record): string => Payment::formatRupiah($record->amount))
                                    ->alignEnd()
                                    ->extraAttributes(['class' => 'tabular-nums']),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['invoice.student.unit', 'receiver']))
            ->columns([
                TextColumn::make('receipt_no')
                    ->label(__('pondok.payment.receipt_no'))
                    ->searchable(),
                TextColumn::make('invoice.student.nis')
                    ->label(__('pondok.payment.nis'))
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('invoice.student.name')
                    ->label(__('pondok.payment.student_name'))
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('invoice.invoice_no')
                    ->label(__('pondok.payment.invoice'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('paid_on')
                    ->label(__('pondok.payment.paid_on'))
                    ->date('j M Y')
                    ->sortable(),
                TextColumn::make('method')
                    ->label(__('pondok.payment.method'))
                    ->formatStateUsing(fn (Payment $record): string => $record->methodLabel()),
                TextColumn::make('amount')
                    ->label(__('pondok.payment.amount'))
                    ->money('IDR', locale: 'id', decimalPlaces: 0)
                    ->alignEnd()
                    ->extraAttributes(['class' => 'tabular-nums'])
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('pondok.payment.status'))
                    ->badge()
                    ->formatStateUsing(fn (Payment $record): string => $record->statusLabel())
                    ->color(fn (Payment $record): string => $record->statusColor()),
                TextColumn::make('receiver.name')
                    ->label(__('pondok.payment.received_by'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('pondok.payment.status'))
                    ->options(static::statusOptions()),
                SelectFilter::make('method')
                    ->label(__('pondok.payment.method'))
                    ->options(collect(Payment::MANUAL_METHODS)
                        ->mapWithKeys(fn (string $method): array => [$method => __("pondok.payment.methods.{$method}")])
                        ->all()),
                SelectFilter::make('unit')
                    ->label(__('pondok.payment.unit'))
                    ->options(fn (): array => Unit::query()->accessible()->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, mixed $unitId) => $query->whereHas('invoice.student', fn (Builder $query) => $query->where('unit_id', $unitId)),
                    )),
                Filter::make('paid_on')
                    ->schema([
                        DatePicker::make('paid_from')
                            ->label(__('pondok.payment.filter_paid_from')),
                        DatePicker::make('paid_until')
                            ->label(__('pondok.payment.filter_paid_until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['paid_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('paid_on', '>=', $date))
                        ->when($data['paid_until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('paid_on', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['paid_from'] ?? null) {
                            $indicators[] = __('pondok.payment.filter_paid_from').' '.static::dateLabel($data['paid_from']);
                        }

                        if ($data['paid_until'] ?? null) {
                            $indicators[] = __('pondok.payment.filter_paid_until').' '.static::dateLabel($data['paid_until']);
                        }

                        return $indicators;
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedBanknotes)
            ->emptyStateHeading(__('pondok.payment.empty'))
            ->emptyStateDescription(__('pondok.payment.empty_description'))
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    static::printReceiptAction(),
                    static::downloadProofAction(),
                    static::cancelAction(),
                ]),
            ]);
    }

    /**
     * Membatalkan pembayaran yang salah input; tersembunyi untuk pembayaran `batal`.
     */
    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('pondok.payment.cancel_action'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('pondok.payment.cancel_heading'))
            ->modalDescription(__('pondok.payment.cancel_description'))
            ->modalSubmitActionLabel(__('pondok.payment.cancel_submit'))
            ->authorize(fn (Payment $record): bool => static::can('cancel', $record))
            ->hidden(fn (Payment $record): bool => ! $record->isCancellable())
            ->schema([
                TextInput::make('reason')
                    ->label(__('pondok.payment.cancel_reason'))
                    ->helperText(__('pondok.payment.cancel_reason_helper'))
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (Payment $record, array $data, CancelPayment $cancel, Action $action): void {
                try {
                    $cancel->handle($record, auth()->user(), (string) $data['reason']);
                } catch (ValidationException $exception) {
                    // Pembayaran sudah dibatalkan di tempat lain: bukan kesalahan isian form.
                    if (array_key_exists('payment', $exception->errors())) {
                        Notification::make()->title($exception->errors()['payment'][0])->danger()->send();
                        $action->cancel();

                        return;
                    }

                    static::runShowingFormErrors($action, fn () => throw $exception);
                }

                Notification::make()
                    ->title(__('pondok.payment.cancelled', ['receipt_no' => $record->receipt_no]))
                    ->success()
                    ->send();
            });
    }

    public static function printReceiptAction(): Action
    {
        return Action::make('printReceipt')
            ->label(__('pondok.payment.print_action'))
            ->icon(Heroicon::OutlinedPrinter)
            ->url(fn (Payment $record): string => static::receiptUrl($record))
            ->openUrlInNewTab();
    }

    public static function downloadProofAction(): Action
    {
        return Action::make('downloadProof')
            ->label(__('pondok.payment.download_proof_action'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->hidden(fn (Payment $record): bool => blank($record->proof_path))
            ->url(fn (Payment $record): string => static::proofUrl($record));
    }

    public static function receiptUrl(Payment $payment): string
    {
        return url("/admin/receipts/{$payment->getKey()}");
    }

    public static function proofUrl(Payment $payment): string
    {
        return url("/admin/payment-proofs/{$payment->getKey()}");
    }

    /**
     * "NIS — Nama" santri pemilik tagihan; null untuk tagihan pendaftar.
     */
    public static function studentLabel(Payment $payment): ?string
    {
        $student = $payment->invoice?->student;

        return $student === null ? null : "{$student->nis} — {$student->name}";
    }

    public static function dateLabel(string $date): string
    {
        return CarbonImmutable::parse($date)->locale('id')->translatedFormat('j M Y');
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return collect(Payment::STATUSES)
            ->mapWithKeys(fn (string $status): array => [$status => __("pondok.payment.statuses.{$status}")])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }
}
