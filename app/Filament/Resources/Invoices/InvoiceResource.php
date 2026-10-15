<?php

namespace App\Filament\Resources\Invoices;

use App\Actions\Keuangan\CancelInvoice;
use App\Actions\Keuangan\GenerateMonthlyInvoices;
use App\Filament\Concerns\BelongsToModule;
use App\Filament\Concerns\ShowsActionErrorsOnForm;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\AcademicYear;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Unit;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

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
                            ->state(fn (Invoice $record): string => "{$record->student?->nis} — {$record->student?->name}"),
                        TextEntry::make('student.unit.name')
                            ->label(__('pondok.invoice.unit'))
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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['student.unit']))
            ->columns([
                TextColumn::make('invoice_no')
                    ->label(__('pondok.invoice.invoice_no'))
                    ->searchable(),
                TextColumn::make('student.nis')
                    ->label(__('pondok.invoice.nis'))
                    ->searchable(),
                TextColumn::make('student.name')
                    ->label(__('pondok.invoice.student_name'))
                    ->searchable(),
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
                        fn (Builder $query, mixed $unitId) => $query->whereHas('student', fn (Builder $query) => $query->where('unit_id', $unitId)),
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
            ->emptyStateIcon(Heroicon::OutlinedDocumentText)
            ->emptyStateHeading(__('pondok.invoice.empty'))
            ->emptyStateDescription(__('pondok.invoice.empty_description'))
            ->emptyStateActions([
                static::generateAction(),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    static::cancelAction(),
                ]),
            ]);
    }

    /**
     * Menjalankan generator untuk satu bulan dalam tahun ajaran aktif, untuk semua jenjang.
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

                try {
                    $result = static::runShowingFormErrors($action, fn (): array => $generate->handle($period, auth()->user()));
                } catch (RuntimeException $exception) {
                    if ($exception->getMessage() !== __('pondok.invoice.errors.number_conflict')) {
                        throw $exception;
                    }

                    Notification::make()->title($exception->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                $skipped = count($result['skipped']);

                Notification::make()
                    ->title(__('pondok.invoice.generated', [
                        'period' => static::periodLabel($period),
                        'created' => $result['created_invoices'],
                        'existing' => $result['existing_invoices'],
                        'skipped' => $skipped,
                    ]))
                    ->body($skipped > 0 ? __('pondok.invoice.generated_skipped_hint') : null)
                    ->success()
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

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'view' => ViewInvoice::route('/{record}'),
        ];
    }
}
